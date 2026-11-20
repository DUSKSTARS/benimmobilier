# Habita — application immobilière PHP

## Installation locale (XAMPP)

1. Démarrer Apache et MySQL depuis le panneau XAMPP.
2. Dans phpMyAdmin, créer ou sélectionner la base `immobilier`, puis importer uniquement [`immobilier.sql`](./immobilier.sql). Ce fichier contient le schéma de la marketplace, de la gestion locative, des ventes et des visites. Sur un hébergeur comme InfinityFree, sélectionnez d’abord la base attribuée dans le panneau d’hébergement : le script ne tente pas de créer une base, opération généralement interdite à l’utilisateur MySQL.
3. Ouvrir le dossier du projet depuis `http://localhost/dashboard/mes_travaux/vente_/`.
4. Ouvrir `http://localhost/dashboard/mes_travaux/vente_/admin/index.php`, se connecter à l’administration puis remplacer le mot de passe temporaire.
5. Créer un compte depuis **Créer un compte** pour publier des biens, gérer son profil et enregistrer ses favoris.
6. Utiliser **Gestion immobilière** dans l’administration pour gérer le patrimoine, les propriétaires, les locataires, les contrats et les finances.

La connexion utilise par défaut `127.0.0.1`, la base `immobilier`, l’utilisateur MySQL `root` et un mot de passe vide, comme dans une installation XAMPP courante. Sur InfinityFree, remplacez ces valeurs par les informations MySQL attribuées par l’hébergeur (`DB_HOST`, `DB_NAME`, `DB_USER` et `DB_PASSWORD`).

Les illustrations immobilières du site et des annonces de démonstration sont stockées dans `images/`, sans chargement d’images depuis un site externe. Les polices utilisent Google Fonts lorsqu’une connexion Internet est disponible. Les montants des annonces sont exprimés en FCFA.

## Images des annonces et sécurité des comptes

Chaque annonce dispose de quatre champs séparés pour téléverser des images JPG, PNG ou WebP depuis l’appareil (5 Mo maximum par image). Le premier champ fournit la couverture ; les images téléversées sont enregistrées dans `images/properties/`, et les autres apparaissent dans le détail où elles peuvent être agrandies. Jusqu’à trois vidéos MP4, WebM ou OGG (100 Mo maximum chacune) peuvent aussi être téléversées depuis l’appareil et lues directement sur la fiche du bien. Sans nouveau fichier, les images et vidéos existantes sont conservées.

Pour choisir la localisation, cliquez sur la carte ou utilisez la position actuelle de l’appareil (autorisation du navigateur requise). La carte interactive utilise OpenStreetMap ; une connexion Internet est nécessaire. La géolocalisation du navigateur fonctionne sur `localhost` ou un site HTTPS.

Si une sélection atteint la limite PHP, augmentez `upload_max_filesize` à au moins `100M` et `post_max_size` à au moins `330M` dans la configuration PHP d’Apache, puis redémarrez Apache.

Les visiteurs peuvent aimer une annonce sans compte ; leurs likes sont rattachés à leur session et mis à jour sans recharger la page. Un acompte Mobile Money simulé de 10 % du prix est disponible depuis chaque fiche publiée. Il enregistre une référence de démonstration et ne déclenche aucun débit ni appel à un opérateur. `immobilier.sql` crée les tables et index nécessaires aux likes invités et à l’historique des simulations lors d’une installation neuve.

Après 30 minutes sans activité, les comptes connectés sont déconnectés. Trois mots de passe incorrects bloquent un compte. Pour le débloquer, un code temporaire à six chiffres est envoyé à l’adresse du compte ; il expire après 30 minutes et cinq codes incorrects imposent d’en demander un nouveau. Le mot de passe n’est jamais envoyé par e-mail.

Pour activer l’envoi des codes, configurez le serveur SMTP avec les variables d’environnement `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` (`tls` ou `ssl`), `MAIL_FROM_ADDRESS` et, facultativement, `MAIL_FROM_NAME`. Gardez les identifiants SMTP dans la configuration de l’environnement, jamais dans le code source.

## Administration et modération

Après avoir importé [`immobilier.sql`](./immobilier.sql), ouvrez `http://localhost/dashboard/mes_travaux/vente_/admin/index.php`. Les identifiants locaux initiaux sont **admin / 123admin**. À la première connexion, l’espace impose immédiatement de remplacer ce mot de passe temporaire par un mot de passe personnel d’au moins 12 caractères. Ne conservez jamais ces identifiants initiaux sur un serveur accessible depuis Internet.

Avant de déployer le compte administrateur initial, remplacez aussi son adresse `admin@habita.local` par une adresse e-mail réellement accessible afin qu’il puisse recevoir les codes de déblocage.

L’administration permet d’examiner les annonces et les coordonnées/profils des vendeurs, de publier ou d’archiver un bien, ou de le refuser avec un motif obligatoire. Les annonces déposées ou modifiées par les membres sont mises en attente de validation. Le vendeur voit le motif de refus dans son espace et peut corriger puis renvoyer son annonce.

Pour une base existante, sauvegardez la base avant d’importer [`immobilier.sql`](./immobilier.sql). Le script ajoute les tables et colonnes manquantes sans supprimer les annonces existantes.

## Gestion locative et patrimoine

L’espace protégé `admin/portfolio.php` suit le nombre de biens disponibles, réservés, loués, vendus ou en travaux, les propriétaires et locataires, les revenus encaissés, les impayés échus, les dépenses, les échéances de contrats et l’activité récente. Il permet aussi de créer/modifier/archiver des biens, de gérer les fiches des propriétaires/locataires et d’enregistrer les baux, paiements, revenus de vente et dépenses. Les contrats arrivant à échéance dans les 60 jours sont signalés.

Les copies de pièces d’identité et documents associés sont stockés dans `private/owner-documents/` et téléchargeables uniquement par un administrateur connecté ; ne désactivez pas la protection Apache de ce dossier.

## Ventes et visites

Les pages réservées aux administrateurs [`admin/sales.php`](./admin/sales.php) et [`admin/visits.php`](./admin/visits.php) gèrent acheteurs, offres, réservations temporaires, acomptes, paiements et historique des ventes. Les visiteurs peuvent demander une visite depuis l’accueil ou la fiche d’un bien. Les demandes non affectées apparaissent comme notifications dans l’administration ; l’administrateur peut leur assigner un agent. Un lien privé est généré lors de la création de l’agent (et renouvelable depuis sa fiche) ; partagez-le uniquement avec la personne concernée. Dans son espace [`agent.php`](./agent.php), l’agent consulte ses visites, voit la localisation et renseigne le compte rendu ; la session agent expire après 30 minutes d’inactivité. Les réservations expirées sont libérées automatiquement lors de l’accès au module des ventes.

Les actions **Acte de vente** et **Reçu** produisent un document HTML imprimable ou enregistrable en PDF depuis le navigateur. Le document de vente est un récapitulatif de transaction et ne remplace pas un acte authentique ni les formalités légales.

## Photo de profil

L’inscription accepte une photo facultative JPG, PNG ou WebP de 3 Mo maximum. Elle est enregistrée dans `images/profiles/` sous un nom aléatoire. Le script SQL ajoute aussi le champ `profile_photo` aux bases existantes.
