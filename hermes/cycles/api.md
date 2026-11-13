## File API (Facebook, Instagram, YouTube)

Réponses aux commentaires reçus par les API officielles ; la prélecture ne retient que les comptes `api_ready` avec persona et action `reply` activée.

Pour chaque compte avec des cibles, charger `reply-contexts --account ID --limit 5` **une seule fois** : un seul persona et jusqu'à cinq cibles, chacune avec sa publication, les messages précédents et la cible. Écarter une cible si le contexte ou un média nécessaire manque. Un commentaire direct peut recevoir une réponse pertinente ; une relance doit contenir une question. Rédiger ensemble les réponses retenues, une réponse et son identifiant par cible, puis les soumettre une par une avec `reply ITEM --context UUID --text "RÉPONSE" --commit` et `reply-status ITEM`. Les cibles non retenues reçoivent leur `review` (voir règles communes).

Faire un premier tour de tous les comptes éligibles, puis poursuivre jusqu'à cinq réponses par compte dans `batch_max`, en alternant les réseaux. Après avoir écarté des cibles impropres, relire au besoin un seul lot pour obtenir d'autres candidats. Pendant le délai minimal d'un compte, avancer sur un autre ; un retard sur Facebook ne retient pas Instagram ou YouTube. Quand le seul obstacle est ce délai et que d'autres réponses sont prêtes, le respecter puis reprendre.

Like : seulement si `auto_like` est vrai (Facebook avec `comment.like` activé) ; attendre la confirmation de la réponse et de son like avant l'envoi suivant du compte. Aucun like Instagram ni YouTube par ce parcours. Facebook : `inbox-like ITEM --commit` pour un like seul, avec relecture API et mémoire anti-renvoi. Un résultat incertain : vérifier l'état enregistré avant de poursuivre sur ce compte, jamais réessayer.
