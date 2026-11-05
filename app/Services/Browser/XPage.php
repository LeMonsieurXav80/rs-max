<?php

namespace App\Services\Browser;

/**
 * Pilotage d'un onglet X, uniquement par le DOM : Camoufox exécute `evaluate` hors
 * du contexte de la page, un script de celle-ci n'y voit donc ni X ni ses requêtes.
 */
class XPage
{
    public const EDITOR = '[data-testid="tweetTextarea_0"]';

    public const SUBMIT = '[data-testid="tweetButton"]';

    public const FILE_INPUT = 'input[data-testid="fileInput"]';

    private const POLL_MICROSECONDS = 750000;

    private const FILL_SECONDS = 5;

    public ?string $lastToast = null;

    public function __construct(
        private CamofoxClient $client,
        public readonly string $userId,
        public readonly string $tabId,
    ) {}

    public function goTo(string $url): void
    {
        $this->client->navigate($this->userId, $this->tabId, $url);
    }

    /** Clic DOM : le clic de Playwright n'aboutit pas sur les boutons de X. */
    public function click(string $selector): void
    {
        $this->evaluate('document.querySelector('.json_encode($selector).').click()');
    }

    public function upload(array $paths): void
    {
        $this->client->upload($this->userId, $this->tabId, $paths, self::FILE_INPUT);
    }

    public function screenshot(): string
    {
        return $this->client->screenshot($this->userId, $this->tabId);
    }

    public function close(): void
    {
        $this->client->close($this->userId, $this->tabId);
    }

    public function exists(string $selector): bool
    {
        return (bool) $this->evaluate('!!document.querySelector('.json_encode($selector).')');
    }

    public function waitFor(string $selector, int $seconds): bool
    {
        return $this->poll($seconds, fn () => $this->exists($selector));
    }

    public function waitUntilGone(string $selector, int $seconds): bool
    {
        return $this->poll($seconds, fn () => ! $this->exists($selector));
    }

    /**
     * Vrai clic du navigateur. Entrée sur le bouton a publié des réponses en double,
     * et le clic DOM comme Ctrl+Entrée n'envoient pas de façon fiable.
     */
    public function submit(): void
    {
        $this->client->click($this->userId, $this->tabId, self::SUBMIT);
    }

    /**
     * L'accueil, où X renvoie après l'envoi, a son propre éditeur : seuls l'URL et le
     * message font foi. Ce message s'efface en quelques secondes : il est gardé pour
     * dire pourquoi X a refusé, par exemple sa limite quotidienne.
     */
    public function waitForPublished(int $seconds): bool
    {
        return $this->poll($seconds, function () {
            $this->lastToast = $this->toast() ?? $this->lastToast;

            return (bool) $this->evaluate('!/^\\/(compose|intent)\\/post/.test(location.pathname)')
                || preg_match('/publié|envoyé|posted|sent/i', (string) $this->lastToast);
        });
    }

    /** Le bouton existe avant d'être actif : il le devient après le traitement des médias. */
    public function waitForSubmit(int $seconds): bool
    {
        return $this->waitFor(self::SUBMIT.':not([aria-disabled="true"])', $seconds);
    }

    public function hasAttachments(int $count): bool
    {
        $found = $this->evaluate('document.querySelectorAll(\'[data-testid="attachments"] video,[data-testid="attachments"] img\').length');

        return (int) $found >= $count;
    }

    /** Nom du compte connecté, `null` si le navigateur est déconnecté. */
    public function identity(): ?string
    {
        $this->waitFor('[data-testid="AppTabBar_Profile_Link"], a[href="/login"], a[href="/i/flow/login"]', 20);
        $path = $this->evaluate('document.querySelector(\'[data-testid="AppTabBar_Profile_Link"]\')?.getAttribute("href")||null');

        return is_string($path) ? strtolower(ltrim($path, '/')) : null;
    }

    /**
     * Vrai copier-coller, relu avant toute suite. La frappe au clavier perd des
     * accents dans l'éditeur de X (« très » devient « trs ») et un collage simulé
     * y arrive vide : seul un Ctrl+V réel transmet le texte exact.
     */
    public function fill(string $text): bool
    {
        $value = json_encode($text, JSON_UNESCAPED_UNICODE);

        $this->evaluate("(()=>{const t=document.createElement('textarea');t.id='rsmax-buffer';t.value={$value};t.style.cssText='position:fixed;top:0;opacity:0';document.body.appendChild(t);t.focus();t.select()})()");
        $this->client->press($this->userId, $this->tabId, 'Control+c');
        $this->evaluate("(()=>{document.getElementById('rsmax-buffer').remove();document.querySelector(".json_encode(self::EDITOR).').focus()})()');
        $this->client->press($this->userId, $this->tabId, 'Control+v');

        return $this->poll(self::FILL_SECONDS, fn () => $this->editorMatches($text));
    }

    /** Ouvre le menu du tweet visé. Sur la page d'une réponse, le premier tweet est son parent. */
    public function openTweetMenu(string $tweetId): bool
    {
        $id = json_encode('/status/'.$tweetId);

        return (bool) $this->evaluate(
            "(()=>{const a=[...document.querySelectorAll('article[data-testid=\"tweet\"]')].find(t=>[...t.querySelectorAll('a[href]')].some(l=>l.getAttribute('href').endsWith({$id})));const c=a?.querySelector('[data-testid=\"caret\"]');if(!c)return false;c.click();return true})()"
        );
    }

    public function clickMenuItem(string $pattern): bool
    {
        $regex = json_encode('^('.$pattern.')$');

        return $this->poll(5, fn () => (bool) $this->evaluate(
            "(()=>{const m=[...document.querySelectorAll('[role=\"menuitem\"]')].find(x=>new RegExp({$regex},'i').test(x.innerText.trim()));if(!m)return false;m.click();return true})()"
        ));
    }

    public function toast(): ?string
    {
        $text = $this->evaluate('document.querySelector(\'[data-testid="toast"]\')?.innerText||null');

        return is_string($text) ? $text : null;
    }

    /** @return array<int, array<string, mixed>> */
    public function tweets(): array
    {
        return $this->evaluate(<<<'JS'
            [...document.querySelectorAll('article[data-testid="tweet"]')].map(a=>{
              const link=[...a.querySelectorAll('a[href*="/status/"]')].find(l=>l.querySelector('time'));
              const m=link?.getAttribute('href').match(/^\/([^/]+)\/status\/(\d+)/);
              if(!m)return null;
              const social=a.querySelector('[data-testid="socialContext"]')?.innerText||'';
              const user=a.querySelector('[data-testid="User-Name"]');
              return{id:m[2],author:m[1].toLowerCase(),name:user?.querySelector('span')?.innerText||null,
                avatar:a.querySelector('[data-testid="Tweet-User-Avatar"] img')?.src||null,
                text:(n=>{if(!n)return'';const c=n.cloneNode(true);c.querySelectorAll('img').forEach(i=>i.replaceWith(i.alt));return c.innerText??c.textContent})(a.querySelector('[data-testid="tweetText"]')),
                published_at:a.querySelector('time')?.getAttribute('datetime')||null,
                stats:a.querySelector('[role="group"]')?.getAttribute('aria-label')||'',
                pinned:/pinned|épinglé/i.test(social),reposted:/repost/i.test(social),
                reply:/^(replying to|en réponse à)/im.test(a.innerText)}
            }).filter(Boolean)
            JS) ?? [];
    }

    public function scrollDown(): void
    {
        $this->evaluate('window.scrollBy(0,window.innerHeight*2)');
    }

    /** Nombre exact publié par X dans la fiche structurée du profil. */
    public function followers(): ?int
    {
        $count = $this->evaluate(<<<'JS'
            (()=>{try{const s=document.querySelector('script[data-testid="UserProfileSchema-test"]');
              const d=JSON.parse(s.textContent),e=d.mainEntity||d.author||d;
              const i=[].concat(e.interactionStatistic||[]).find(x=>/Follow/.test(x.interactionType||x.name||''));
              return i?.userInteractionCount??null}catch{return null}})()
            JS);

        return is_numeric($count) ? (int) $count : null;
    }

    /** « 3 réponses, 2 reposts, 12 J'aime, 1 signet, 900 vues », selon la langue du compte. */
    public static function parseStats(string $label): array
    {
        $patterns = ['replies' => 'r[ée]ponses?|repl(?:y|ies)', 'reposts' => 'reposts?|retweets?', 'likes' => "j[’']+aime|likes?",
            'bookmarks' => 'signets?|bookmarks?', 'views' => 'vues?|views?'];
        $stats = [];

        foreach ($patterns as $key => $pattern) {
            if (preg_match('/([\d\s\x{202F}\x{A0},.]*\d)\s*(?:'.$pattern.')/iu', $label, $m)) {
                $stats[$key] = (int) preg_replace('/\D/', '', $m[1]);
            }
        }

        return $stats;
    }

    private function editorMatches(string $text): bool
    {
        $editor = json_encode(self::EDITOR);
        $typed = (string) $this->evaluate("document.querySelector({$editor})?.innerText||''");

        return self::comparable($typed) === self::comparable($text);
    }

    private static function comparable(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private function poll(int $seconds, callable $check): bool
    {
        $deadline = microtime(true) + $seconds;

        do {
            if ($check()) {
                return true;
            }
            usleep(self::POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function evaluate(string $expression): mixed
    {
        return $this->client->evaluate($this->userId, $this->tabId, $expression);
    }
}
