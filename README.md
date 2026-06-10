# IoT Salle de sport — Groupe G9 ISEP

Site web PHP de supervision d'une salle de sport connectée.  
Lit des capteurs IoT (TIVA / port série) et affiche les données dans un tableau de bord partagé.

## Architecture

```
/projet_commun
├── config/db.php                  → Connexion PDO (modifier les identifiants ici)
├── includes/
│   ├── auth.php                   → Sessions, CSRF, contrôle d'accès
│   ├── fonctions.php              → Fonctions utilitaires (formatage, météo, badges…)
│   ├── header.php / footer.php    → En-tête/pied de page HTML
├── public/
│   ├── index.php                  → Accueil (état en temps réel)
│   ├── connexion.php              → Connexion utilisateur
│   ├── inscription.php            → Inscription
│   ├── deconnexion.php            → Déconnexion
│   ├── tableau_de_bord.php        → Tous les capteurs, filtres, pagination
│   ├── analyse.php                → Statistiques & comparaisons inter-groupes
│   ├── capteur.php                → Détail + graphique d'un capteur
│   ├── actionneurs.php            → Pilotage LED et OLED
│   └── assets/css/style.css       → Feuille de style (sans framework)
├── scripts/
│   ├── seed.php                   → Insère les capteurs et données de démo
│   └── lecture_proximite.php      → Daemon CLI : port série → BDD
└── sql/schema.sql                 → Schéma à importer dans phpMyAdmin
```

## Installation

### 1. Prérequis

- **XAMPP** ≥ 8.1 (Apache + MySQL/MariaDB + PHP)
- PHP CLI disponible : `C:\xampp\php\php.exe`
- Port USB de la carte TIVA identifié (Gestionnaire de périphériques Windows → Ports COM)

### 2. Copier le projet

Déposez le dossier `projet_commun` dans `C:\xampp\htdocs\`.

Accès web : `http://localhost/projet_commun/public/index.php`

### 3. Créer la base de données

1. Démarrez XAMPP (Apache + MySQL).
2. Ouvrez **phpMyAdmin** : `http://localhost/phpmyadmin`
3. Créez la base **`iot_salle_sport`** (ou laissez le script SQL la créer).
4. Onglet **SQL** → importez `sql/schema.sql`.

### 4. Configurer la connexion

Éditez `config/db.php` si nécessaire :

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'iot_salle_sport');
define('DB_USER', 'root');
define('DB_PASS', '');   // XAMPP : vide par défaut
```

### 5. Peupler la base (données de démo)

```bash
C:\xampp\php\php.exe scripts/seed.php
```

Ou via le navigateur (uniquement en local) :  
`http://localhost/projet_commun/scripts/seed.php`

**Comptes créés :**

| E-mail | Mot de passe | Rôle |
|---|---|---|
| admin@isep.fr | admin1234 | Admin |
| etudiant@isep.fr | pass1234 | Utilisateur |

### 6. Activer l'extension PHP Direct IO

Ouvrez `C:\xampp\php\php.ini` et décommentez (ou ajoutez) :

```ini
extension=php_dio
```

Redémarrez Apache dans le panneau XAMPP.

> **Vérification :** `php -m | findstr dio` doit retourner `dio`.

### 7. Lancer la lecture du capteur de proximité

Éditez d'abord `scripts/lecture_proximite.php` :

```php
const COM_PORT   = 'COM22';   // ← remplacez par votre port (ex. COM3, COM7…)
const CAPTEUR_ID = 4;         // ← vérifiez l'ID dans la BDD après seed
```

Puis lancez en **CLI** (laissez le terminal ouvert) :

```bash
C:\xampp\php\php.exe scripts/lecture_proximite.php
```

Le script tourne en boucle, lit chaque trame de la TIVA et insère la valeur en base.  
**Ne jamais lancer ce script depuis le navigateur** (dio_read est bloquant).

## Équipes — Groupe G9

| Équipe | Capteur / Actionneur | Rôle |
|---|---|---|
| G9A | Capteur sonore | Volume ambiant, alerte si > 85 dB |
| G9B | Température + Humidité | Confort thermique |
| **G9E** | **Capteur de proximité** | **Comptage personnes + occupation machines** |
| G9D | Capteur de gaz (CO₂) | Qualité de l'air |
| G9C | LED + OLED | État postes (vert/rouge) + affichage OLED |

## Format de trame attendu (TIVA → PC)

Le script `lecture_proximite.php` extrait le **premier nombre** (entier ou décimal) de chaque ligne reçue. Exemples de trames valides :

```
42
Proximite: 42.5 cm
DIST=38
```

Si votre TIVA envoie un format différent, modifiez la constante `TRAME_PATTERN` dans le script.

## Fonctionnalités

- **Accueil** : occupation en temps réel, météo extérieure (open-meteo, sans clé), résumé des capteurs
- **Tableau de bord** : tous les capteurs avec filtres (groupe / équipe / type) et pagination
- **Analyse** : statistiques SQL (MIN/MAX/AVG/COUNT), graphique de comparaison inter-groupes, synthèse par type
- **Détail capteur** : graphique d'évolution (Chart.js), historique paginé, stats sur période personnalisable
- **Actionneurs** : envoi de commandes LED (libre/occupé/off) et OLED (messages prédéfinis), historique
- **Authentification** : inscription, connexion, CSRF, mots de passe hachés
- **Alertes** : seuils configurables par capteur (e-mail via `mail()`)
- **Météo** : API open-meteo (gratuit, sans clé)

## Éco-conception

- CSS maison sans framework, typo système, SVG pour les icônes
- Chart.js chargé uniquement sur les pages graphiques
- Agrégations SQL (`AVG`/`MIN`/`MAX`/`GROUP BY`) plutôt que traitement PHP en mémoire
- Requêtes `LIMIT`/pagination pour ne charger que les données nécessaires
- Lecture série : 1 mesure/seconde maximum (`DELAI_LECTURE`)

## Sécurité

- Requêtes PDO préparées (anti-injection SQL) sur toutes les pages
- Mots de passe : `password_hash()` + `password_verify()` (bcrypt)
- Tokens CSRF sur tous les formulaires
- `htmlspecialchars()` sur toutes les sorties HTML
- Liste blanche des commandes actionneurs côté serveur
- Contrôle d'accès par session sur les pages protégées
