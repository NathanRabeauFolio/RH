# MAK Galerie — V10.2 · Interface V2 bis

Application interne PHP, GitHub-ready, pour :

- notes de frais
- titres-resto
- Navigo
- congés
- calendrier d’équipe
- exports Word / PDF
- journal des connexions

## Utilisateurs

- Valerie K — mot de passe temporaire : `valerie`
- Nathan Rabeau — mot de passe temporaire : `nathan`
- Raphaele Skupien — mot de passe temporaire : `raphaele`
- Pauline Lepreux — mot de passe temporaire : `pauline`
- Olivier Pasquier — mot de passe temporaire : `olivier`
- Laura — mot de passe temporaire selon la même convention (à remplacer dès que possible).

Les mots de passe sont hachés dans `app/config.default.php`.

## Points forts V10.2

- logo MAK intégré dans les exports Word au format carré `2,2 cm × 2,2 cm`
- export Word des notes de frais avec aperçu imprimé des images
- aperçu imprimé des PDF dans le Word via rendu image du PDF
- annexes visuelles : `2 par page` pour les formats portrait, `1 par page` pour les formats plus larges
- export PDF calendrier sur A4 paysage, avec 2 mois par page
- déclinaison de bleus par collaborateur dans le calendrier PDF
- export admin `Titres-resto + Navigo` enrichi avec les jours de congés posés sur le mois
- journal des connexions téléchargeable via une URL non évidente
- sauvegardes JSON automatiques avant chaque écriture
- écritures atomiques + verrous de fichiers pour limiter les pertes de données
- mises à jour par mois pour Titres-resto et Navigo, pour éviter les doublons inutiles

## Local

### Docker
```bash
cp config.local.example.php config.local.php
make up
```

### Sans Docker
```bash
cp config.local.example.php config.local.php
php -S 127.0.0.1:8080 -t public
```

## Déploiement

Le projet contient :

- `deploy/bootstrap_lightsail.sh`
- `deploy/deploy_release.sh`
- `.github/workflows/deploy.yml`

Le document principal est :
- `docs/INSTALLATION_GITHUB_LIGHTSAIL.md`

## Règles métier

- Nathan Rabeau : repos dimanche + lundi
- Raphaele Skupien / Pauline Lepreux / Olivier Pasquier : repos samedi + dimanche
- jours fériés français exclus du calcul des congés
- congés payés : statut initial `Demandé`
- maladie : statut initial `Accordé`
- autres : statut initial `Accordé`
- notes de frais : plusieurs lignes à la fois
- congés : plusieurs lignes à la fois
- justificatifs facultatifs
- modification / suppression côté employé
- validation admin pour les congés payés

## Journal des connexions

Le nom de route est défini dans `app/config.default.php` via `login_journal_route`.
Par défaut :

- `public/journal_8f3a6c1d4e2b.php`

Cette route exige une session admin active.

## Sauvegardes

Les sauvegardes automatiques sont stockées dans :

- `storage/backups/expenses/`
- `storage/backups/tickets/`
- `storage/backups/navigo/`
- `storage/backups/leaves/`

Le journal de connexions est stocké dans :

- `storage/logs/login_journal.ndjson`

### Jours de repos travaillés

Chaque salarié déclare ses dates de repos effectivement travaillées (motif facultatif). Les déclarations sont prises en compte immédiatement, sans validation administrative. Le mois RH de rattachement est distinct de la date travaillée (par défaut le mois courant). Les déclarations tardives figurent uniquement dans ce mois, avec leur date réelle et leur motif. Le Word RH mensuel réunit toutes les données dans un seul tableau paysage, avec le total de jours par salarié et celui de l'équipe, sans calcul de compensation. Le fichier de données `storage/data/rest_work.json` est créé à la première déclaration et utilise le mécanisme existant de sauvegardes. Les congés et les autres exports sont inchangés.
