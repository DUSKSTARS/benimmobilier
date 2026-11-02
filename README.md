# Habita — application immobilière PHP

## Installation locale (XAMPP)

1. Démarrer Apache et MySQL depuis le panneau XAMPP.
2. Dans phpMyAdmin, créer ou sélectionner la base `immobilier`, puis importer [`immobilier.sql`](./immobilier.sql). Sur un hébergeur comme InfinityFree, sélectionnez d’abord la base attribuée dans le panneau d’hébergement : le script ne tente pas de créer une base, opération généralement interdite à l’utilisateur MySQL.
3. Ouvrir le dossier du projet depuis `http://localhost/dashboard/mes_travaux/vente_/`.
4. Ouvrir `http://localhost/dashboard/mes_travaux/vente_/admin/index.php`, se connecter à l’administration puis remplacer le mot de passe temporaire.
5. Créer un compte depuis **Créer un compte** pour publier des biens, gérer son profil et enregistrer ses favoris.

La connexion utilise par défaut `127.0.0.1`, la base `immobilier`, l’utilisateur MySQL `root` et un mot de passe vide, comme dans une installation XAMPP courante. Sur InfinityFree, remplacez ces valeurs par les informations MySQL attribuées par l’hébergeur (`DB_HOST`, `DB_NAME`, `DB_USER` et `DB_PASSWORD`).

Les illustrations immobilières du site et des annonces de démonstration sont stockées dans `images/`, sans chargement d’images depuis un site externe. Les polices utilisent Google Fonts lorsqu’une connexion Internet est disponible. Les montants des annonces sont exprimés en FCFA.

## Images des annonces et sécurité des comptes

Chaque annonce peut téléverser jusqu’à quatre images JPG, PNG ou WebP depuis l’appareil (5 Mo maximum par image). La première image choisie est utilisée comme couverture ; les autres apparaissent dans le détail et peuvent être agrandies. Lors d’une modification, les images existantes sont conservées si aucune nouvelle image n’est choisie. `immobilier.sql` crée la table `property_images` et ajoute les colonnes nécessaires aux bases existantes.

Si une sélection atteint la limite PHP, augmentez `upload_max_filesize` à au moins `5M` et `post_max_size` à au moins `24M` dans la configuration PHP d’Apache, puis redémarrez Apache.

Les visiteurs peuvent aimer une annonce sans compte ; leurs likes sont rattachés à leur session et mis à jour sans recharger la page. Un acompte Mobile Money simulé de 10 % du prix est disponible depuis chaque fiche publiée. Il enregistre une référence de démonstration et ne déclenche aucun débit ni appel à un opérateur. Réimportez `immobilier.sql` pour créer les tables et index nécessaires aux likes invités et à l’historique des simulations.

Après 30 minutes sans activité, les comptes connectés sont déconnectés. Trois mots de passe incorrects bloquent un compte. Pour le débloquer, un code temporaire à six chiffres est envoyé à l’adresse du compte ; il expire après 30 minutes et cinq codes incorrects imposent d’en demander un nouveau. Le mot de passe n’est jamais envoyé par e-mail.

Pour activer l’envoi des codes, configurez le serveur SMTP avec les variables d’environnement `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` (`tls` ou `ssl`), `MAIL_FROM_ADDRESS` et, facultativement, `MAIL_FROM_NAME`. Gardez les identifiants SMTP dans la configuration de l’environnement, jamais dans le code source.

## Administration et modération

Après avoir importé [`immobilier.sql`](./immobilier.sql), ouvrez `http://localhost/dashboard/mes_travaux/vente_/admin/index.php`. Les identifiants locaux initiaux sont **admin / 123admin**. À la première connexion, l’espace impose immédiatement de remplacer ce mot de passe temporaire par un mot de passe personnel d’au moins 12 caractères. Ne conservez jamais ces identifiants initiaux sur un serveur accessible depuis Internet.

Avant de déployer le compte administrateur initial, remplacez aussi son adresse `admin@habita.local` par une adresse e-mail réellement accessible afin qu’il puisse recevoir les codes de déblocage.

L’administration permet d’examiner les annonces et les coordonnées/profils des vendeurs, de publier ou d’archiver un bien, ou de le refuser avec un motif obligatoire. Les annonces déposées ou modifiées par les membres sont mises en attente de validation. Le vendeur voit le motif de refus dans son espace et peut corriger puis renvoyer son annonce.

Pour mettre à jour une base déjà créée, réimportez le script `immobilier.sql` dans phpMyAdmin en sélectionnant la base `immobilier`. Les modifications de colonnes sont conçues pour ajouter les champs de modération sans supprimer les comptes ou les annonces des membres. Le script supprime les six annonces de démonstration préchargées.

## Photo de profil

L’inscription accepte une photo facultative JPG, PNG ou WebP de 3 Mo maximum. Elle est enregistrée dans `images/profiles/` sous un nom aléatoire. Le script SQL ajoute aussi le champ `profile_photo` aux bases existantes.
