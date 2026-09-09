# Desplegar Yupana (dev y prod)

Guía paso a paso para poner en funcionamiento el backend de contabilidad NIIF.
Todo se corre desde `yupana/backend`, salvo donde se indique lo contrario.
Asumo que ya tenés `bob-contruye` clonado como hermano de este proyecto (en
`../../bob-contruye`, como los demás backends) y con tus últimos cambios
locales (las entidades/DDL de Yupana ya están ahí, tag `v1.80`).

El plan de cuentas NIIF **no** vive en una tabla de DynamoDB — va bundleado
como JSON dentro del propio deploy (`package/Common/src/Data/plan-cuentas-niif.json`,
353 cuentas). Es dato de referencia estático, no operativo: se corrige con un
commit + deploy si hace falta, no con un seed manual aparte. Eso significa que
acá **no hay paso de "sembrar el catálogo"** — cada ambiente queda listo apenas
se despliega.

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

Trae `vendor/bref/bref` (el plugin de serverless.yml lo necesita local, aunque
en Lambda el código corre desde el layer compartido).

## 3. Crear la tabla de DynamoDB — DEV

Solo hay **una** tabla real (el clon de cuentas por empresa; las de
asientos/detalles/mayor son las otras cuatro). Desde `bob-contruye/` (no desde
`yupana/backend/`):

```bash
cd ../../bob-contruye
php dev-scripts/DynamoDB/DDL/cuentaContableProfileTable.php --environment dev --prefix yupana
php dev-scripts/DynamoDB/DDL/asientoContableTable.php --environment dev --prefix yupana
php dev-scripts/DynamoDB/DDL/asientoContableDetalleTable.php --environment dev --prefix yupana
php dev-scripts/DynamoDB/DDL/mayorContableTable.php --environment dev --prefix yupana
cd -
```

Crea `dev_yupana_cuentas_profiles`, `dev_yupana_asientos`,
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
GET  - https://xxxxxxxxxx.execute-api.us-east-1.amazonaws.com/perfiles/{profileId}/cuentas
...
```

Copiá el dominio base (`https://xxxxxxxxxx.execute-api.us-east-1.amazonaws.com`,
sin el path) y ponelo en `caja-registradora/frontend/.env` como
`NUXT_PUBLIC_API_YUPANA_BASE=`, y volvé a levantar/desplegar el frontend — ya
quedó todo el código listo, solo falta esa URL.

Verificación rápida de que el runtime conecta bien a DynamoDB:

```bash
curl https://xxxxxxxxxx.execute-api.us-east-1.amazonaws.com/test-runtime
```

Debería devolver `"status": "✅ Conexión Exitosa"`.

Desde ahí ya podés probar de punta a punta: entrá a un perfil `company` en la
app, `Contabilidad → Plan de Cuentas`, y tocá "Activar módulo de contabilidad"
— clona las 353 cuentas del JSON bundleado hacia esa empresa, sin ningún paso
manual previo.

## 5. Repetir para PROD

Mismos pasos, cambiando el ambiente:

```bash
# Tabla
cd ../../bob-contruye
php dev-scripts/DynamoDB/DDL/cuentaContableProfileTable.php --environment prod --prefix yupana
php dev-scripts/DynamoDB/DDL/asientoContableTable.php --environment prod --prefix yupana
php dev-scripts/DynamoDB/DDL/asientoContableDetalleTable.php --environment prod --prefix yupana
php dev-scripts/DynamoDB/DDL/mayorContableTable.php --environment prod --prefix yupana
cd -

# Deploy
npm run deploy:prod
```

Y actualizá también la variable de entorno de producción del frontend
(`NUXT_PUBLIC_API_YUPANA_BASE`) con la URL de prod, en el pipeline/host donde
esté configurada (no en el `.env` local, ese es solo para dev).

## Notas

- `tableCreation.sh` en `bob-contruye/` ya tiene agregadas las 4 líneas de
  Yupana (`--environment prod`) para cuando reconstruyas todo el ambiente de
  cero.
- Si en algún momento hace falta corregir o ampliar el catálogo NIIF (por
  ejemplo si la Superintendencia publica una actualización), se edita
  `package/Common/src/Data/plan-cuentas-niif.json` directamente y se
  redespliega — no hace falta re-sembrar nada, y las empresas que ya activaron
  el módulo **no** se ven afectadas retroactivamente (su clon ya está creado;
  solo las empresas que activen el módulo después del deploy ven el catálogo
  actualizado).
- El script que generó ese JSON a partir del PDF oficial fue
  `scratchpad/build_plan_cuentas_json.py` de esta sesión — no forma parte del
  repo, pedímelo si hace falta reconstruirlo desde cero.
