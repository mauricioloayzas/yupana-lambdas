# Desplegar Yupana (dev y prod)

Guía paso a paso para poner en funcionamiento el backend de contabilidad NIIF.
Todo se corre desde `yupana/backend`, salvo donde se indique lo contrario.
Asumo que ya tenés `bob-contruye` clonado como hermano de este proyecto (en
`../../bob-contruye`, como los demás backends) y con tus últimos cambios
locales (las entidades/DDL de Yupana ya están ahí, tag `v1.79`).

## 0. Prerrequisitos (una sola vez)

- PHP 8.4, Composer.
- Node/npm y el CLI de `serverless` instalado globalmente (`npm i -g serverless`
  si `which serverless` no devuelve nada — los demás backends lo usan así, sin
  tenerlo como dependencia del proyecto).
- AWS CLI configurado con el perfil `mauloasan` (`aws configure --profile mauloasan`
  si todavía no existe) — es el mismo perfil que usan los demás backends.
- `bob-contruye/.env` con `AWS_REGION`/`AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`
  ya debería existir (lo usan los scripts DDL de los otros proyectos). Si no
  existe, copialo del mismo lugar de donde salió para los demás.

## 1. Variables de entorno de este proyecto

Yupana usa el mismo User Pool de Cognito que toda la plataforma, así que las
variables son idénticas a las de `personal-finances/backend`. Más simple que
transcribirlas a mano (una de ellas es un secreto):

```bash
cp ../../personal-finances/backend/.env.local .env.local
cp ../../personal-finances/backend/.env.prod.local .env.prod.local
```

Estos dos archivos están en `.gitignore`, no se commitean.

## 2. Instalar dependencias locales

```bash
composer install
```

Esto trae `vendor/bref/bref` (el plugin de serverless.yml lo necesita local,
aunque en Lambda el código realmente corre desde el layer compartido) y las
librerías que usa `commands/cuentaCommand.php` (Guzzle).

## 3. Crear las tablas de DynamoDB — DEV

Desde `bob-contruye/` (no desde `yupana/backend/`):

```bash
cd ../../bob-contruye
php dev-scripts/DynamoDB/DDL/cuentaContableTable.php --environment dev --prefix yupana
php dev-scripts/DynamoDB/DDL/cuentaContableProfileTable.php --environment dev --prefix yupana
php dev-scripts/DynamoDB/DDL/asientoContableTable.php --environment dev --prefix yupana
php dev-scripts/DynamoDB/DDL/asientoContableDetalleTable.php --environment dev --prefix yupana
php dev-scripts/DynamoDB/DDL/mayorContableTable.php --environment dev --prefix yupana
cd -
```

Crea `dev_yupana_cuentas`, `dev_yupana_cuentas_profiles`, `dev_yupana_asientos`,
`dev_yupana_asientos_detalles`, `dev_yupana_mayor` (con sus índices). Cada
script es idempotente — si la tabla ya existe, lo avisa y no falla.

## 4. Desplegar a DEV

```bash
npm run deploy:dev
```

Esto: carga `.env.local`, reconstruye y publica el layer compartido
`mauloasan-shared-dev-vendor` (desde el `bob-contruye` local — por eso el paso
0 importa: si tu checkout de `bob-contruye` no tiene los cambios de Yupana, el
layer tampoco los va a tener), y corre `serverless deploy --stage dev`.

Al terminar, la terminal imprime las rutas HTTP con su dominio de API Gateway,
algo como:

```
POST - https://xxxxxxxxxx.execute-api.us-east-1.amazonaws.com/perfiles/{profileId}/cuentas/init
GET  - https://xxxxxxxxxx.execute-api.us-east-1.amazonaws.com/cuentas
...
```

Copiá el dominio base (`https://xxxxxxxxxx.execute-api.us-east-1.amazonaws.com`,
sin el path) — lo necesitás en dos lugares:

**a)** Frontend: en `caja-registradora/frontend/.env`, completá
`NUXT_PUBLIC_API_YUPANA_BASE=` con esa URL, y volvé a levantar/desplegar el
frontend (ya quedó todo el código listo, solo falta esa URL).

**b)** Para el seed del catálogo (paso 5).

Verificación rápida de que el runtime conecta bien a DynamoDB:

```bash
curl https://xxxxxxxxxx.execute-api.us-east-1.amazonaws.com/test-runtime
```

Debería devolver `"status": "✅ Conexión Exitosa"`.

## 5. Sembrar el catálogo maestro NIIF — DEV

Con la URL del paso 4 y un usuario/clave válidos contra el `auth` del
orquestador (el mismo login que usás en la app):

```bash
php commands/cuentaCommand.php \
  --file plan-cuentas-niif.csv \
  --api https://xxxxxxxxxx.execute-api.us-east-1.amazonaws.com \
  --auth-api https://0dpp2nb3rd.execute-api.us-east-1.amazonaws.com \
  --email tu@correo.com \
  --password 'tu-clave'
```

(`--auth-api` es la URL de `orchestrator`/auth — la misma que
`NUXT_PUBLIC_API_AUTH_BASE` en el `.env` del frontend. Si tu usuario y clave no
querés pasarlos como flags de shell, exportá `YUPANA_SEED_EMAIL` /
`YUPANA_SEED_PASSWORD` en su lugar y omitilos del comando.)

El script imprime una línea `OK: <codigo> - <nombre>` por cada cuenta creada y
al final un resumen `Listo: N cuentas creadas, M fallidas`. Deberían ser 353
creadas, 0 fallidas. **Corré esto una sola vez** — si lo corrés dos veces vas
a duplicar todo el catálogo maestro (no tiene protección de idempotencia,
a propósito, para poder re-sembrar borrando la tabla si hace falta corregir algo).

En este punto ya podés probar desde la app: entrá a un perfil `company`,
`Contabilidad → Plan de Cuentas`, y tocá "Activar módulo de contabilidad".

## 6. Repetir para PROD

Mismos pasos, cambiando el ambiente:

```bash
# Tablas
cd ../../bob-contruye
php dev-scripts/DynamoDB/DDL/cuentaContableTable.php --environment prod --prefix yupana
php dev-scripts/DynamoDB/DDL/cuentaContableProfileTable.php --environment prod --prefix yupana
php dev-scripts/DynamoDB/DDL/asientoContableTable.php --environment prod --prefix yupana
php dev-scripts/DynamoDB/DDL/asientoContableDetalleTable.php --environment prod --prefix yupana
php dev-scripts/DynamoDB/DDL/mayorContableTable.php --environment prod --prefix yupana
cd -

# Deploy
npm run deploy:prod

# Seed (con la URL de prod que imprima el deploy, y el auth-api de prod)
php commands/cuentaCommand.php \
  --file plan-cuentas-niif.csv \
  --api https://<url-prod-yupana>.execute-api.us-east-1.amazonaws.com \
  --auth-api https://<url-prod-auth>.execute-api.us-east-1.amazonaws.com \
  --email tu@correo.com \
  --password 'tu-clave'
```

Y actualizá también la variable de entorno de producción del frontend
(`NUXT_PUBLIC_API_YUPANA_BASE`) con la URL de prod, en el pipeline/host donde
esté configurada (no en el `.env` local, ese es solo para dev).

## Notas

- `tableCreation.sh` en `bob-contruye/` ya tiene agregadas las 5 líneas de
  Yupana (`--environment prod`) para cuando reconstruyas todo el ambiente de
  cero.
- Si en algún momento hace falta re-generar `plan-cuentas-niif.csv` desde el
  PDF oficial, el script que lo generó fue
  `scratchpad/build_plan_cuentas_csv.py` de esta sesión — no forma parte del
  repo, pedímelo si hace falta reconstruirlo.
