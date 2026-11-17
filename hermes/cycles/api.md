## File API (Facebook, Instagram, YouTube, Bluesky)

Réponses aux commentaires reçus par les API officielles ; la prélecture ne retient que les comptes `api_ready` avec persona et action `reply` activée.

Cinq passages par jour : traiter tout ce qui attend. Pour chaque compte avec des cibles, charger `reply-contexts --account ID --limit N` **une seule fois**, N = `batch_max` (30 au plus) : un seul persona et les cibles, chacune avec sa publication, les messages précédents et la cible. Écarter une cible si le contexte ou un média nécessaire manque. Un commentaire direct peut recevoir une réponse pertinente ; une relance doit contenir une question. Rédiger ensemble toutes les réponses retenues, puis les remettre **en un seul appel** : `replies --json '[{"id":ITEM,"context":"UUID","text":"RÉPONSE"}]' --commit`. RS-Max les envoie lui-même en respectant le délai du compte : ne pas attendre, ne pas appeler `reply-status`, passer au compte suivant. Les cibles non retenues reçoivent leur `review` (voir règles communes). `refused` dans le résultat ne concerne que sa cible ; `check_required` : déjà demandée, ne rien renvoyer.

Tous les comptes éligibles dans le même passage. Au passage suivant, `reply-status ITEM` seulement pour une réponse signalée incertaine.

Like : seulement si `auto_like` est vrai (Facebook avec `comment.like` activé) ; attendre la confirmation de la réponse et de son like avant l'envoi suivant du compte. Aucun like Instagram, YouTube ni Bluesky par ce parcours ; Bluesky : 300 caractères au plus. Facebook : `inbox-like ITEM --commit` pour un like seul, avec relecture API et mémoire anti-renvoi. Un résultat incertain : vérifier l'état enregistré avant de poursuivre sur ce compte, jamais réessayer.
