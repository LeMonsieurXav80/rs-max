<div class="px-6 py-5 border-t space-y-3" x-data="rsMetaConsole({{ $metaAccount->id }}, @js($platform), @js($metaAccount->agent_strategy['facebook_groups'] ?? []), @js($metaAccount->agent_strategy['hashtags'] ?? []))">
    <h3 class="text-sm font-semibold">Agir depuis le {{ str_starts_with($metaAccount->browser_profile ?? '', 'vps-') ? 'VPS' : 'navigateur distant' }}</h3>
    <p class="text-xs text-gray-500">Activer les lectures et les gestes voulus ci-dessus, puis enregistrer. Les mêmes règles s’appliquent aux recettes lancées dans Companion sur votre ordinateur.</p>
    @if($platform === 'instagram')
        <p class="text-sm">Likes automatiques des commentaires : <strong>{{ ($metaAccount->agent_strategy['instagram_comment_likes_enabled'] ?? false) ? 'activés' : 'désactivés' }}</strong>.
            <a href="{{ route('accounts.edit', $metaAccount) }}" class="text-indigo-600 underline">Régler le passage et les hashtags</a>
        </p>
        @if($metaPause = app(\App\Services\Agent\MetaBrowserAgent::class)->platformPauseUntil($metaAccount))
            <p class="text-sm text-amber-700">Instagram limite temporairement les accès. Passage en pause jusqu’au {{ \Illuminate\Support\Carbon::parse($metaPause)->timezone('Europe/Lisbon')->format('d/m à H:i') }} (Lisbonne).</p>
        @endif
    @endif
    <select x-model="mode" class="rounded-lg border-gray-300 text-sm" :disabled="busy">
        @if($platform === 'facebook')
            <option value="group.read">Explorer un groupe choisi</option>
        @else
            <option value="search.read">Explorer un hashtag</option>
            <option value="profile.read">Lire un profil</option>
            <option value="followback.read">Vérifier le retour d’abonnement</option>
        @endif
        <option value="thread.read">Lire une publication et ses commentaires</option>
    </select>
    <template x-if="mode === 'group.read'"><select x-model="url" class="w-full rounded-lg border-gray-300 text-sm" :disabled="busy"><option value="">Choisir un groupe</option><template x-for="g in groups" :key="g"><option :value="g" x-text="g"></option></template></select></template>
    <template x-if="mode === 'thread.read'"><input x-model="url" class="w-full rounded-lg border-gray-300 text-sm" placeholder="Lien de la publication" :disabled="busy"></template>
    <template x-if="mode === 'search.read'"><input x-model="hashtag" class="w-full rounded-lg border-gray-300 text-sm" placeholder="#hashtag" :disabled="busy"></template>
    <template x-if="['profile.read', 'followback.read'].includes(mode)"><input x-model="handle" class="w-full rounded-lg border-gray-300 text-sm" placeholder="Compte sans @" :disabled="busy"></template>
    <button type="button" @click="load()" :disabled="busy" class="px-3 py-2 rounded-lg bg-indigo-600 text-white text-sm disabled:opacity-50">Lire</button>
    <p role="status" class="text-sm whitespace-pre-wrap" x-text="message"></p>
    <template x-for="item in result.items || []" :key="item.id"><button type="button" @click="url=item.url; mode='thread.read'; load()" :disabled="busy" class="block text-sm underline text-indigo-600" x-text="item.url"></button></template>
    <template x-if="result.target">
        <div class="space-y-3">
            <p class="text-sm whitespace-pre-wrap" x-text="result.target.text"></p>
            <template x-if="platform === 'facebook' && mode === 'thread.read'">
                <div class="space-y-2">
                    <textarea x-model="comment" maxlength="500" rows="3" class="w-full rounded-lg border-gray-300 text-sm" placeholder="Votre commentaire sur la publication" :disabled="busy"></textarea>
                    <button type="button" @click="draft()" :disabled="busy" class="text-sm underline text-indigo-600">Proposer avec le persona</button>
                    <button type="button" @click="send('comment', null, true)" :disabled="busy || !comment.trim()" class="px-3 py-2 text-sm rounded-lg border">Vérifier à blanc</button>
                    <button type="button" @click="send('comment')" :disabled="busy || !comment.trim()" class="px-3 py-2 text-sm rounded-lg bg-indigo-600 text-white">Publier le commentaire</button>
                </div>
            </template>
            <template x-if="platform === 'instagram' && mode === 'thread.read'"><button type="button" @click="send('like')" :disabled="busy" class="px-3 py-2 text-sm rounded-lg border">Aimer la publication</button></template>
            <template x-if="mode === 'profile.read'"><div class="flex gap-2"><button type="button" @click="send('follow')" :disabled="busy" class="px-3 py-2 text-sm rounded-lg border">Suivre</button><button type="button" @click="send('unfollow')" :disabled="busy" class="px-3 py-2 text-sm rounded-lg border">Désuivre si éligible</button></div></template>
        </div>
    </template>
    <template x-for="c in result.comments || []" :key="c.id"><div class="text-sm border-t pt-2"><p><strong x-text="c.author"></strong> <span x-text="c.text"></span></p><button type="button" @click="send('comment.like', c.id)" :disabled="busy || c.liked === true" class="text-indigo-600 underline disabled:opacity-50" x-text="c.liked ? 'Déjà aimé' : 'Aimer ce commentaire'"></button></div></template>
    @if($platform === 'instagram')<p class="text-xs text-gray-500">Le désabonnement concerne uniquement les suivis RS-Max : délai réglé dans la fiche, deux vérifications sans retour espacées d’au moins 15 minutes, puis dernier contrôle avant le geste.</p>@endif
</div>
@once
<script>
function rsMetaConsole(id, platform, groups, hashtags) {
    return {id,platform,groups,mode:platform==='facebook'?'group.read':'search.read',url:groups[0]||'',hashtag:hashtags[0]||'',handle:'',comment:'',message:'',busy:false,result:{},task:null,
        async api(method,path,body){const r=await fetch('/agent/meta/'+path,{method,headers:{'Accept':'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},...(body?{body:JSON.stringify(body)}:{})});const d=await r.json();if(!r.ok)throw Error(d.message||'Action refusée.');return d;},
        async wait(task){for(let i=0;i<120;i++){const d=await this.api('GET','tasks/'+task);if(!['pending','running'].includes(d.status)){if(d.status!=='done')throw Error(d.error||d.status);return d;}await new Promise(r=>setTimeout(r,1500));}throw Error('Tâche toujours en cours : consulter le journal, sans la renvoyer.');},
        async load(){this.busy=true;this.result={};this.comment='';this.message='Lecture en cours…';try{const d=await this.api('POST','accounts/'+id+'/read',{action:this.mode,...(['group.read','thread.read'].includes(this.mode)?{url:this.url}:{}),...(this.mode==='search.read'?{hashtag:this.hashtag}:{}),...(['profile.read','followback.read'].includes(this.mode)?{handle:this.handle}:{}),limit:30});const r=await this.wait(d.task_id);this.task=r.task_id;this.result=r.result;this.message=r.result.follow_back?'Retour d’abonnement : '+r.result.follow_back:'Lecture terminée.';}catch(e){this.message=e.message;}finally{this.busy=false;}},
        async draft(){this.busy=true;try{const d=await this.api('POST','tasks/'+this.task+'/draft',{});this.comment=d.text;this.message='Proposition à relire.';}catch(e){this.message=e.message;}finally{this.busy=false;}},
        async send(action,target,dry=false){this.busy=true;this.message=dry?'Vérification…':'Geste en cours…';try{let hash;if(action==='comment')hash=(await this.api('GET','tasks/'+this.task+'/context')).persona_hash;const d=await this.api('POST','accounts/'+id+'/act',{read_task_id:this.task,action,...(target?{target_id:target}:{}),...(action==='comment'?{text:this.comment,persona_hash:hash}:{}),dry_run:dry,request_key:crypto.randomUUID()});if(d.dry_run){this.message='Aperçu valide. Aucun geste effectué.';return;}if(d.duplicate){this.message='Geste déjà réservé ('+d.status+'). Aucun renvoi.';return;}await this.wait(d.task_id);this.message='Geste confirmé après rechargement.';if(target){const c=this.result.comments.find(c=>c.id===target);if(c)c.liked=true;}}catch(e){this.message=e.message;}finally{this.busy=false;}}
    };
}
</script>
@endonce
