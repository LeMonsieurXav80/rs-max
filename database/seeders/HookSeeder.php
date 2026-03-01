<?php

namespace Database\Seeders;

use App\Models\Hook;
use App\Models\HookCategory;
use Illuminate\Database\Seeder;

class HookSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            [
                'name' => 'Guide Voyage',
                'slug' => 'guide-voyage',
                'color' => '#10b981',
                'hooks' => [
                    "Planifier un voyage à prend des jours. Cet article de fait le tri. Voici l'itinéraire parfait résumé en [Nombre] tweets.",
                    "Oubliez les guides classiques sur. J'ai épluché le dernier reportage de [Auteur], voici les [Nombre] spots locaux que personne ne connaît.",
                    "Ne réservez rien pour avant d'avoir lu ça. J'ai synthétisé les conseils de ce nouveau dossier pour éviter les pires pièges à touristes.",
                    "L'erreur que font 90% des gens en visitant? Ce guide de l'explique parfaitement. Décryptage pour voyager plus intelligemment.",
                    "Visiter en [Nombre] jours sans s'épuiser. C'est le défi relevé par cet excellent article de [Auteur]. J'ai résumé le plan de route.",
                    "Le budget réel pour partir à en [Année] n'a plus rien à voir avec vos souvenirs. Analyse claire des coûts basée sur la dernière enquête de.",
                    "Si est sur votre bucket list, arrêtez de scroller. J'ai condensé le guide ultime de [Auteur] en [Nombre] conseils actionnables.",
                    ": cet article sur va vous faire gagner des heures de recherche. L'essentiel à retenir ⬇️",
                    "Tout le monde s'entasse à [Lieu très connu]. Pourtant, ce reportage de propose une alternative bien plus fascinante à. Explications.",
                    "J'ai lu les [Nombre] pages du dernier guide de sur. Voici les seules [Nombre] choses dont vous avez vraiment besoin pour réussir votre séjour.",
                    "Voyager à pendant nécessite une préparation stricte. Ce qu'il faut absolument savoir, tiré de l'analyse de [Auteur].",
                    "Les [Nombre] règles non écrites pour se fondre dans la masse à. Une lecture passionnante signée que j'ai traduite en points clés.",
                    "On pense souvent que est inaccessible pour un petit budget. Cet article démontre le contraire avec des données précises. Résumé de la méthode.",
                    "Le quartier de [Lieu spécifique] à est en pleine mutation. Synthèse du passionnant dossier de sur ce phénomène urbain.",
                    "Avant de faire vos valises pour, prenez 2 minutes pour lire ce fil. Les [Nombre] recommandations incontournables de [Auteur].",
                ],
            ],
            [
                'name' => 'Anecdotes & Histoire Pays',
                'slug' => 'anecdotes-histoire-pays',
                'color' => '#3b82f6',
                'hooks' => [
                    "La plupart des gens pensent connaître [Pays]. Mais cet essai fascinant de [Auteur] dévoile une réalité historique totalement ignorée. Résumé.",
                    "L'histoire derrière la création de [Monument/Institution] à [Pays] est digne d'un film. J'ai lu l'enquête de, voici les faits les plus fous.",
                    "Il y a [Nombre] ans, [Événement] bouleversait [Pays]. Ce nouvel article de apporte un éclairage inédit. Les [Nombre] découvertes majeures.",
                    "Pourquoi [Pays] est le seul endroit au monde où l'on trouve [Fait insolite]? La réponse se trouve dans cette brillante analyse de [Auteur].",
                    "On a longtemps cru que [Mythe historique sur le pays]. C'est faux. Ce papier de démonte l'idée reçue avec des preuves solides.",
                    "Le secret le mieux gardé de la culture [Nationalité]. Une plongée sociologique fascinante signée [Auteur] que j'ai condensée pour vous.",
                    "Comment un simple [Objet/Concept] a changé le destin de [Pays] tout entier. Retour sur l'enquête passionnante publiée aujourd'hui par.",
                    "Derrière la carte postale de [Pays/Ville] se cache une histoire brutale et complexe. Synthèse du long format magistral de [Auteur].",
                    "Ce chiffre hallucinant sur la population de [Pays] m'a fait repenser ma vision de la région. Explication rapide tirée de la dernière étude de.",
                    "La tradition de [Nom de la tradition] en [Pays] n'a rien de naturel, c'est une invention récente. L'histoire vraie, résumée depuis l'article de [Auteur].",
                    "J'adore quand l'histoire contredit la fiction. Le vrai visage de [Personnage historique du pays] selon les nouvelles archives dévoilées par.",
                    "L'anecdote politique la plus absurde de [Pays] a eu des conséquences mondiales. J'ai épluché l'analyse de [Auteur] pour en tirer cette chronologie.",
                    "Si vous aimez la géopolitique, l'article de sur la frontière entre [Pays A] et est un indispensable. L'essentiel en [Nombre] tweets.",
                    "Comment [Pays] a réussi à éradiquer [Problème sociétal/maladie] en seulement [Nombre] ans. Les leçons d'un miracle étudié par [Auteur].",
                    "Le mystère de [Lieu/Événement] en [Pays] vient (peut-être) d'être résolu par. Voici les conclusions de cette enquête hors norme.",
                ],
            ],
            [
                'name' => 'Actualité & Décryptage',
                'slug' => 'actualite-decryptage',
                'color' => '#f59e0b',
                'hooks' => [
                    "L'actualité sur est saturée de bruit. Cet article de remet les faits à plat. Voici l'essentiel à comprendre aujourd'hui.",
                    "Tout le monde réagit à chaud sur, mais peu ont lu le rapport complet. Je l'ai fait pour vous. Les [Nombre] points clés qui changent la donne.",
                    "La nouvelle loi/réforme sur expliquée sans jargon. J'ai décortiqué l'excellente analyse de [Auteur] pour n'en retenir que l'impact réel sur votre quotidien.",
                    "Ce qui se joue vraiment derrière l'annonce de [Entreprise/Personnalité politique]. Une lecture critique basée sur l'enquête approfondie de.",
                    "Ne vous fiez pas aux gros titres sur l'affaire. Le dossier publié par montre une réalité bien plus nuancée. Synthèse en [Nombre] points.",
                    "Le conflit autour de n'est pas né hier. Ce long format de [Auteur] retrace les origines de la crise. Un résumé nécessaire pour comprendre la situation.",
                    "Alors que tout le monde regarde, une actualité majeure concernant vient de tomber sur. Pourquoi c'est important ⬇️",
                    "L'interview de [Personnalité] chez dure. C'est dense, mais brillant. Voici ses [Nombre] déclarations les plus impactantes.",
                    "La crise de vient de franchir un nouveau cap. Décryptage des causes profondes à travers les données exclusives de [Auteur].",
                    "Fin de la rumeur : voici factuellement ce qui va se passer pour. Un résumé clair basé sur l'article de référence de.",
                    "Les médias généralistes passent à côté de l'information principale concernant. L'analyse spécialisée de [Auteur] remet les pendules à l'heure.",
                    "Ce changement radical sur va impacter [Public ciblé]. J'ai lu la documentation technique/article de, voici le guide de survie.",
                    "La vraie raison pour laquelle [Événement récent] a eu lieu. Une enquête sans concession de que j'ai découpée pour une lecture rapide.",
                    "Le chiffre du jour :. Ce que cette donnée isolée par [Auteur] nous dit sur l'état actuel de notre société/économie.",
                    "L'édito de [Auteur] sur est la meilleure chose écrite sur le sujet cette semaine. J'en ai extrait l'argumentaire central en [Nombre] tweets.",
                ],
            ],
            [
                'name' => 'Gagner de l\'argent & Économie',
                'slug' => 'gagner-argent-economie',
                'color' => '#8b5cf6',
                'hooks' => [
                    "Oubliez les vendeurs de rêve sur. Cet article très documenté de [Auteur] détaille avec de vrais chiffres comment optimiser vos revenus. Décryptage.",
                    "L'analyse de sur le marché de est une mine d'or factuelle. J'ai extrait les [Nombre] stratégies les plus viables pour investir en [Année].",
                    "La plupart des conseils sur sont obsolètes. Ce rapport de [Auteur] démontre ce qui fonctionne réellement aujourd'hui. Résumé.",
                    "Comment [Entreprise/Personne] a généré [Montant] sans lever de fonds. J'ai démonté la mécanique de leur succès d'après la brillante étude de cas de.",
                    "[Public ciblé, ex: Freelances / Investisseurs] : arrêtez de faire cette erreur de tarification. Les données publiées par [Auteur] sont sans appel. Explications.",
                    "Le modèle économique caché derrière [Industrie/Produit]. Une analyse financière de qui explique précisément où va l'argent.",
                    "Gagner sa vie avec [Compétence/Outil] n'est pas magique, c'est un système. L'article de [Auteur] détaille le plan d'action étape par étape. Je vous le résume.",
                    "La règle des [Nombre] en matière d'épargne vient d'être remise en question par cette étude de. Pourquoi il faut adapter sa stratégie maintenant.",
                    "Ce que les créateurs à [Montant/Chiffre d'affaires] font différemment des autres. Les [Nombre] leçons tirées de l'interview de [Auteur] chez.",
                    "Investir dans en [Année] : l'analyse objective des risques et opportunités selon le dernier rapport de.",
                    "La fausse bonne idée financière qui ruine silencieusement les [Public cible]. Ce papier de [Auteur] met le doigt là où ça fait mal. Décorticage.",
                    "Créer une nouvelle ligne de revenus avec [Méthode] : l'étude de cas réaliste et chiffrée de. Pas de fausse promesse, juste la méthode.",
                    "L'inflation a changé les règles du jeu pour la fixation des prix. Comment s'adapter? Les recommandations tactiques de l'économiste [Auteur].",
                    "Négocier une augmentation de [Pourcentage] dans un marché tendu. Le script psychologique détaillé par, vulgarisé pour vous.",
                    "L'anatomie d'un business très rentable avec très peu de clients. J'ai décortiqué le modèle présenté aujourd'hui par [Auteur] dans son dernier article.",
                ],
            ],
            [
                'name' => 'Problème, Solution & Productivité',
                'slug' => 'probleme-solution-productivite',
                'color' => '#ef4444',
                'hooks' => [
                    "Vous passez vos journées à lutter contre [Problème, ex: la procrastination]? La méthode contre-intuitive détaillée aujourd'hui par pourrait tout changer. Résumé.",
                    "J'ai lu des dizaines d'articles sur [Problème], mais l'approche de [Auteur] est radicalement différente. Voici pourquoi son système fonctionne vraiment.",
                    "On a tous le même problème avec. Ce brillant article de propose un framework (cadre de travail) en [Nombre] étapes pour s'en débarrasser définitivement.",
                    "Le mythe de la \"discipline militaire\" pour réussir [Action]. L'essai de [Auteur] prouve qu'un petit ajustement d'environnement suffit. L'essentiel en [Nombre] tweets.",
                    "Si vous vous sentez bloqué avec [Problème technique ou psychologique], arrêtez ce que vous faites. Le tutoriel de est la seule solution dont vous avez besoin.",
                    "Les [Nombre] habitudes toxiques qui détruisent silencieusement votre capacité à [Action, ex: vous concentrer]. Et comment les remplacer selon [Auteur].",
                    "Vous avez l'impression de travailler beaucoup sans avancer sur [Objectif]? Le concept de \"[Nom du concept de l'article]\" expliqué par est la réponse.",
                    "Pourquoi les méthodes classiques de échouent systématiquement. La réponse cinglante et le plan d'action de [Auteur].",
                    "L'outil le plus sous-estimé pour résoudre vos problèmes de. L'analyse complète publiée sur, simplifiée pour une application immédiate.",
                    "Transformer [Problème majeur] en avantage compétitif. L'étude de cas fascinante de [Auteur] montre le chemin inverse de ce que font les autres.",
                    "[Public ciblé] : vous galérez toujours avec [Problème spécifique]? Cette méthode en [Nombre] points tirée du dernier billet de est d'une efficacité redoutable.",
                    "J'ai testé le système de productivité de [Auteur] présenté dans. Voici exactement comment l'implémenter dans votre quotidien dès ce matin.",
                    "Arrêtez d'essayer de résoudre [Problème] en utilisant [Mauvaise solution]. L'article de met en lumière la seule métrique qui compte vraiment.",
                    "La fatigue liée à [Problème cognitif ou pro] est un mal du siècle. La solution ne demande pas de repos, mais de la restructuration. L'explication de [Auteur].",
                    "Comment automatiser sans savoir coder/sans se ruiner. J'ai traduit le jargon technique de l'excellent guide de en étapes simples.",
                ],
            ],
            [
                'name' => 'Séduction, Élégance & Charisme',
                'slug' => 'seduction-elegance-charisme',
                'color' => '#ec4899',
                'hooks' => [
                    "On pense souvent que l'élégance se résume à [Concept, ex: porter des vêtements chers]. Cet article de [Auteur] prouve que c'est faux. Voici la vraie règle en [Nombre] points.",
                    "L'erreur que 90% des gens font en matière de séduction n'a rien à voir avec le physique. C'est [Autre aspect]. L'essentiel de l'analyse de [Auteur] ⬇️",
                    "Comment [Personnalité] a transformé sa présence dans une pièce en appliquant le principe de [Concept]. Une étude de cas fascinante sur le charisme signée [Auteur].",
                    "Le secret d'une première impression réussie tient dans les [Nombre] premières secondes. Les conseils psychologiques de [Auteur] vulgarisés en quelques tweets.",
                    "Vous avez toujours l'impression de [Problème, ex: passer inaperçu en soirée]? La méthode d'approche détaillée par [Auteur] est une véritable leçon d'intelligence sociale. Décryptage.",
                    "Le charisme n'est pas un don magique réservé aux extravertis. C'est un ensemble de [Nombre] micro-habitudes. J'ai condensé le guide de [Auteur] pour vous.",
                    "Oubliez les \"techniques de drague\" manipulatoires. L'article de [Auteur] redéfinit l'art de plaire avec une approche radicalement différente et bienveillante. Explications.",
                    "Vos mots disent une chose, votre posture en dit une autre. Les [Nombre] signaux non-verbaux décryptés par [Auteur] qui ruinent votre charme (et comment les corriger).",
                    "La vraie séduction commence par [Action/Concept intérieur, ex: l'écoute active]. Ce long format de [Auteur] m'a fait repenser ma vision de la confiance en soi. Synthèse.",
                    "La règle du [Nom de la règle] expliquée par [Auteur] est le secret le mieux gardé de l'élégance masculine/féminine. Comment l'appliquer au quotidien.",
                    "Le trait de caractère le plus attirant n'est pas celui qu'on croit. J'ai lu l'étude sociologique partagée par [Auteur], voici ce qu'il faut en retenir.",
                    "Comment naviguer dans un événement mondain avec grâce quand on est. Le plan d'action de [Auteur] étape par étape.",
                    "Les tendances passent, l'élégance reste. J'ai extrait les [Nombre] règles intemporelles du dernier dossier de [Auteur/Magazine]. Un vrai guide de savoir-vivre.",
                    ": arrêtez de sur-analyser vos interactions. Le concept de \"[Concept]\" présenté par [Auteur] va vous simplifier la vie.",
                    "J'ai analysé la communication verbale de [Personnalité/Personnage] dans. Ce n'est pas inné, c'est une technique précise. Résumé de l'analyse de [Auteur].",
                ],
            ],
        ];

        foreach ($data as $order => $categoryData) {
            $category = HookCategory::create([
                'name' => $categoryData['name'],
                'slug' => $categoryData['slug'],
                'color' => $categoryData['color'],
                'sort_order' => $order,
            ]);

            foreach ($categoryData['hooks'] as $content) {
                Hook::create([
                    'hook_category_id' => $category->id,
                    'content' => $content,
                ]);
            }
        }
    }
}
