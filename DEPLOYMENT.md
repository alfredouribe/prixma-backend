# Despliegue a producción — VPS

Guía completa para dejar el backend de Prixma corriendo en un VPS real (pensada para Hostinger con Ubuntu 22.04/24.04 — ajusta los comandos si tu distro es distinta). Cúbrela de arriba a abajo la primera vez; al final hay una sección corta para deploys futuros.

Dominio asumido en toda la guía: **`prixma.site`** (el real, confirmado en `CLAUDE.md` — no `prixma.app`, que quedó como resabio de un placeholder viejo en algunos `.env.example`/`MAIL_FROM_ADDRESS`).

- API: `api.prixma.site`
- WebSockets (Reverb): `ws.prixma.site`

---

## 0. Antes de empezar

- Apunta los registros DNS **antes** de pedir certificados SSL (la validación de Let's Encrypt los necesita resueltos):
  - `A` — `api.prixma.site` → IP del VPS
  - `A` — `ws.prixma.site` → IP del VPS
- Conéctate por SSH como root (o un usuario con sudo) la primera vez.
- Recomendado antes de instalar nada: crear un usuario no-root para desplegar (evita correr todo como root).

```bash
adduser prixma
usermod -aG sudo prixma
su - prixma
```

Del resto de la guía en adelante, asume que estás logueado como ese usuario (`prixma`), no como root.

---

## 1. Actualizar el sistema

```bash
sudo apt update && sudo apt upgrade -y
```

---

## 2. PHP 8.3 y extensiones

El proyecto pide PHP 8.3+ (ver `CLAUDE.md`/`constitution.md`; `composer.json` acepta `^8.2` como mínimo técnico, pero usa 8.3 para tener paridad con el entorno local). Verifica primero si ya viene instalado:

```bash
php -v
```

Si no está en 8.3.x, instala desde el PPA de Ondřej Surý (el estándar para PHP actualizado en Ubuntu):

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

sudo apt install -y php8.3 php8.3-fpm php8.3-cli php8.3-common \
  php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip \
  php8.3-bcmath php8.3-intl php8.3-gd php8.3-redis
```

Extensiones y por qué cada una:

| Extensión | Para qué |
|---|---|
| `php8.3-mysql` | Conexión a MySQL (Eloquent) |
| `php8.3-mbstring` | Requerido por Laravel |
| `php8.3-xml` | Requerido por Laravel/Composer |
| `php8.3-curl` | HTTP client (Guzzle, usado por `kreait/firebase-php`) |
| `php8.3-zip` | Composer necesita extraer paquetes |
| `php8.3-bcmath` | Requerido por Laravel |
| `php8.3-intl` | Recomendado por Filament |
| `php8.3-gd` | Recomendado por Filament para previews de imágenes |
| `php8.3-redis` | Extensión nativa de Redis (alternativa a `predis/predis`, ver nota abajo) |

Verifica:

```bash
php -v
php -m | grep -E "mysqli|mbstring|curl|redis"
```

> **Nota sobre Redis:** el proyecto ya trae `predis/predis` en `composer.json` (cliente 100% en PHP, sin necesitar la extensión nativa). Si prefieres no compilar/instalar `php8.3-redis`, usa `REDIS_CLIENT=predis` en el `.env` de producción — no necesitas la extensión del sistema en ese caso. Si sí instalas `php8.3-redis`, puedes usar `REDIS_CLIENT=phpredis` (así lo trae `.env.example`) para mejor rendimiento. Cualquiera de las dos funciona; no dejes el `.env` a medias (client `phpredis` sin la extensión instalada, o viceversa).

---

## 3. Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

---

## 4. MySQL 8

```bash
sudo apt install -y mysql-server
sudo mysql_secure_installation
```

Crea la base de datos y un usuario dedicado (evita usar `root` en producción, a diferencia del `.env` local):

```bash
sudo mysql
```

```sql
CREATE DATABASE prixma CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'prixma_app'@'localhost' IDENTIFIED BY 'UNA_CONTRASEÑA_FUERTE_AQUI';
GRANT ALL PRIVILEGES ON prixma.* TO 'prixma_app'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Guarda esa contraseña — va en `DB_PASSWORD` del `.env` de producción.

---

## 5. Redis

```bash
sudo apt install -y redis-server
sudo systemctl enable redis-server
sudo systemctl status redis-server
```

---

## 6. Node.js 20 LTS

Necesario para compilar los assets de Filament (Vite/Tailwind) — `vite@7` requiere Node 20.19+ o 22.12+, no sirve una versión vieja de Node del repo por defecto de Ubuntu.

```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
node -v
npm -v
```

---

## 7. ffmpeg

Requisito no negociable de `constitution.md` — el pipeline de media (fotos/video de perfil, verificación de identidad) comprime todo con `ffmpeg` antes de subir a S3.

```bash
sudo apt install -y ffmpeg
ffmpeg -version
```

---

## 8. Nginx

```bash
sudo apt install -y nginx
```

---

## 9. Clonar el proyecto

```bash
sudo mkdir -p /var/www/prixma
sudo chown prixma:prixma /var/www/prixma
cd /var/www/prixma
git clone git@github.com:alfredouribe/prisma-spec-driven-development.git .
```

Si el repo usa submódulos (ver `CLAUDE.md` → menciona `--recurse-submodules`):

```bash
git submodule update --init --recursive
```

---

## 10. Configurar `.env` de producción

```bash
cd backend
cp .env.example .env
```

Edita `.env` con estos valores (los que cambian respecto al `.env` local están marcados):

```env
APP_NAME=Prixma
APP_ENV=production          # ⚠️ cambia de local a production
APP_KEY=                    # se genera en el siguiente paso, déjalo vacío
APP_DEBUG=false             # ⚠️ nunca true en producción
APP_URL=https://api.prixma.site   # ⚠️ dominio real, no localhost

APP_LOCALE=es
APP_FALLBACK_LOCALE=es

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_LEVEL=error              # ⚠️ error, no debug, para no llenar el disco de logs

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=prixma
DB_USERNAME=prixma_app       # ⚠️ el usuario dedicado del paso 4, no root
DB_PASSWORD=UNA_CONTRASEÑA_FUERTE_AQUI

SESSION_DRIVER=redis
SESSION_LIFETIME=120

BROADCAST_CONNECTION=reverb
FILESYSTEM_DISK=s3           # ⚠️ s3 real, no local
QUEUE_CONNECTION=redis       # ⚠️ redis, no sync

CACHE_STORE=redis

REDIS_CLIENT=predis          # o phpredis si instalaste php8.3-redis — ver nota del paso 2
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=                 # ⚠️ pendiente: elegir un proveedor real (ver nota abajo)
MAIL_FROM_ADDRESS="hola@prixma.site"   # ⚠️ .site, no .app
MAIL_FROM_NAME="${APP_NAME}"

AWS_ACCESS_KEY_ID=...         # mismas credenciales reales que ya usas en local
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=prixma
AWS_URL=https://prixma.s3.us-east-1.amazonaws.com

SANCTUM_TOKEN_EXPIRATION=10080

# Reverb — ver la sección 16 de esta guía para la distinción entre el
# puerto interno (donde corre el proceso) y el público (detrás de Nginx).
REVERB_APP_ID=...             # regenera credenciales nuevas para prod, no reuses las de local
REVERB_APP_KEY=...
REVERB_APP_SECRET=...
REVERB_HOST=ws.prixma.site    # ⚠️ dominio público, no localhost
REVERB_PORT=443               # ⚠️ el puerto que ve el cliente (detrás de Nginx/SSL)
REVERB_SCHEME=https
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080       # el puerto interno real donde escucha `reverb:start`

FCM_CREDENTIALS_PATH=firebase/service-account.json   # ver paso 14, el archivo se sube aparte

GOOGLE_MAPS_API_KEY=          # pendiente de siempre — Android Maps sin configurar, no bloquea el resto

REVENUECAT_WEBHOOK_SECRET=    # ⚠️ genera un secreto nuevo para producción, no reuses el de pruebas con ngrok
```

**Pendiente que no es parte de esta guía pero hay que resolver antes de mandar correos reales:** `MAIL_MAILER` sigue en `log` en todos los entornos hasta ahora — el correo de baneo (`UserBannedMail`) y cualquier futuro correo transaccional no se envían de verdad. Antes de lanzar necesitas elegir un proveedor SMTP real (Amazon SES es la opción más natural ya que el proyecto ya usa AWS, pero Postmark/Mailgun también sirven) y llenar `MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD`.

Genera la `APP_KEY`:

```bash
php artisan key:generate --force
```

---

## 11. Instalar dependencias y compilar assets

```bash
composer install --no-dev --optimize-autoloader
npm install
npm run build
```

`--no-dev` es importante — evita instalar Pest/Telescope/Sail (herramientas de desarrollo) en el servidor de producción.

---

## 12. Base de datos y storage

```bash
php artisan migrate --force
php artisan storage:link
php artisan db:seed --class=AdminSeeder   # crea el superadmin del panel — ver README.md, cambia la contraseña por defecto después
```

---

## 13. Permisos de archivos

```bash
sudo chown -R www-data:prixma /var/www/prixma/backend
sudo chmod -R 775 storage bootstrap/cache
```

---

## 14. Subir credenciales sensibles

Estos archivos **nunca están en git** — se copian manualmente desde tu máquina al servidor:

```bash
# Desde tu máquina local, no desde el servidor:
scp backend/storage/app/private/firebase/service-account.json prixma@api.prixma.site:/var/www/prixma/backend/storage/app/private/firebase/
```

Verifica permisos después de copiarlo (debe quedar legible solo por el usuario que corre PHP-FPM):

```bash
sudo chmod 600 storage/app/private/firebase/service-account.json
```

---

## 15. Optimizar para producción

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

> Recuerda: cualquier cambio futuro a `.env` requiere `php artisan config:clear` (o volver a correr `config:cache`) para que se refleje — ver la nota que ya dejamos documentada sobre esto en la sesión de RevenueCat.

---

## 16. Nginx — configuración

### API (`api.prixma.site`)

```nginx
# /etc/nginx/sites-available/api.prixma.site
server {
    listen 80;
    server_name api.prixma.site;
    root /var/www/prixma/backend/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        client_max_body_size 210M;   # coincide con los límites de subida de fotos/video del Makefile
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### WebSockets — Reverb (`ws.prixma.site`)

Reverb corre como su propio proceso (no PHP-FPM) escuchando internamente en `127.0.0.1:8080` (paso 18). Nginx solo hace de proxy reverso con SSL:

```nginx
# /etc/nginx/sites-available/ws.prixma.site
server {
    listen 80;
    server_name ws.prixma.site;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;

        # Necesario para que el handshake de WebSocket funcione a través del proxy
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 60s;
    }
}
```

Activa ambos sitios:

```bash
sudo ln -s /etc/nginx/sites-available/api.prixma.site /etc/nginx/sites-enabled/
sudo ln -s /etc/nginx/sites-available/ws.prixma.site /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 17. SSL — Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d api.prixma.site -d ws.prixma.site
```

Certbot reescribe automáticamente los bloques de Nginx de arriba para forzar HTTPS y agrega la renovación automática (verifica con `sudo certbot renew --dry-run`).

---

## 18. Procesos persistentes — Supervisor

Colas y Reverb necesitan seguir corriendo después de que cierres la sesión SSH, y reiniciarse solos si el servidor reinicia.

```bash
sudo apt install -y supervisor
```

**Cola de trabajos** (notificaciones, envío de correo, etc.):

```ini
; /etc/supervisor/conf.d/prixma-worker.conf
[program:prixma-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/prixma/backend/artisan queue:work redis --queue=default,notifications --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=prixma
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/prixma/backend/storage/logs/worker.log
stopwaitsecs=3600
```

**Reverb (WebSockets):**

```ini
; /etc/supervisor/conf.d/prixma-reverb.conf
[program:prixma-reverb]
command=php /var/www/prixma/backend/artisan reverb:start --host=0.0.0.0 --port=8080
autostart=true
autorestart=true
user=prixma
redirect_stderr=true
stdout_logfile=/var/www/prixma/backend/storage/logs/reverb.log
```

Aplica:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

Deberías ver `prixma-worker:prixma-worker_00`, `prixma-worker:prixma-worker_01` y `prixma-reverb` como `RUNNING`.

---

## 19. Cron — scheduler de Laravel

Aunque hoy no hay ninguna tarea programada definida, es buena práctica dejarlo listo (Laravel lo requiere para cualquier `Schedule::command()` futuro, como limpieza de tokens expirados de Sanctum):

```bash
crontab -e
```

Agrega:

```
* * * * * cd /var/www/prixma/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## 20. Firewall básico

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status
```

No abras el puerto 8080 (Reverb) ni el 3306 (MySQL) ni el 6379 (Redis) al exterior — solo Nginx necesita hablar con ellos, y lo hace vía `127.0.0.1`.

---

## 21. Verificación post-deploy

```bash
curl https://api.prixma.site/api/health
# Esperado: {"status":"ok"}

curl -I https://ws.prixma.site
# Esperado: alguna respuesta HTTP (no "connection refused") — confirma que el proxy llega a Reverb
```

Checklist final:

- [ ] `https://api.prixma.site/api/health` responde `200`
- [ ] `https://api.prixma.site/admin` carga el login de Filament
- [ ] `sudo supervisorctl status` — worker y reverb en `RUNNING`
- [ ] Un archivo de prueba sube y se ve reflejado en el bucket S3 real
- [ ] Los certificados SSL de `certbot` están activos (`sudo certbot certificates`)

---

## 22. Actualizar servicios externos que apuntaban al entorno de pruebas

Una vez que el VPS esté arriba y verificado, hay que apagar los atajos temporales que usamos mientras no existía:

1. **RevenueCat → Webhooks** — cambia la URL de `https://TU-TUNEL.ngrok-free.dev/api/webhooks/revenuecat` a `https://api.prixma.site/api/webhooks/revenuecat`, y actualiza el `Authorization header value` con el `REVENUECAT_WEBHOOK_SECRET` nuevo de producción (nunca reuses el que se probó con ngrok).
2. **`frontend/.env`** (o mejor, una variable de entorno de EAS para el build de producción):
   ```env
   EXPO_PUBLIC_API_URL=https://api.prixma.site/api
   EXPO_PUBLIC_WS_URL=wss://ws.prixma.site
   ```
3. Vuelve a correr `eas build --platform android --profile production` con esas variables apuntando ya al VPS real, no a tu IP local.
4. Puedes apagar el túnel de ngrok y detener el `php artisan serve` local que usamos solo para las pruebas de RevenueCat.

---

## Deploys futuros (una vez que todo lo de arriba ya existe)

Para actualizar el código en el VPS después del primer despliegue:

```bash
cd /var/www/prixma/backend
git pull origin main
composer install --no-dev --optimize-autoloader
npm install && npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo supervisorctl restart prixma-worker:*
sudo supervisorctl restart prixma-reverb
```

Vale la pena convertir esto en un script (`deploy.sh`) una vez que el flujo esté probado una o dos veces manualmente.
