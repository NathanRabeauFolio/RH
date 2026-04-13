# Installation GitHub + Lightsail

## 1. Créer le dépôt GitHub
```bash
git init
git add .
git commit -m "Initial V10.2"
git branch -M main
git remote add origin git@github.com:VOTRE_ORGA/VOTRE_REPO.git
git push -u origin main
```

## 2. Préparer Lightsail
Sur l’instance :
```bash
sudo bash deploy/bootstrap_lightsail.sh
```

Ce script installe aussi les dépendances nécessaires à l’aperçu des PDF dans les exports Word :
- `poppler-utils` (`pdftoppm`)
- `ghostscript`

## 3. Créer les secrets GitHub

- `LIGHTSAIL_HOST`
- `LIGHTSAIL_USER`
- `LIGHTSAIL_SSH_KEY`
- `LIGHTSAIL_APP_PATH`

## 4. Déploiement
À chaque push sur `main`, le workflow `deploy.yml` publie une release sur Lightsail.

## 5. Stockage persistant

Créer côté serveur :

- `/var/www/mak-galerie-admin/shared/storage/data`
- `/var/www/mak-galerie-admin/shared/storage/uploads`
- `/var/www/mak-galerie-admin/shared/storage/backups`
- `/var/www/mak-galerie-admin/shared/storage/logs`

et faire pointer la release courante dessus.

## 6. Important pour les exports Word

Les images sont intégrées directement dans les DOCX.
Les PDF sont prévisualisés dans les DOCX via rendu image du PDF.
Si `pdftoppm` n’est pas disponible, l’application tente un fallback via `ghostscript`.
