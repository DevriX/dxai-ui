# Local WP

## Create the site

1. Open Local (`C:\Program Files (x86)\Local\Local.exe`).
2. **+** → site name `DXAI-UI` (domain `dxai-ui.local`).
3. Custom environment: PHP **8.2**, nginx, **MySQL**.
4. WordPress user `admin` / `dxaiadmin` (no special characters).
5. Approve UAC for hosts + SSL. **Trust** the certificate.

Or, with Local running, GraphQL `addSite` (token in `%APPDATA%\Local\graphql-connection-info.json` — do not commit it).

## Link the plugin

```bat
mklink /J "C:\Users\DevriX\Local Sites\DXAI-UI\app\public\wp-content\plugins\dxai-ui" "C:\Users\DevriX\Documents\DXAI-UI"
```

Then activate:

```text
wp plugin activate dxai-ui
```

Admin: `https://dxai-ui.local/wp-admin/admin.php?page=dxai-ui`

Do not update this plugin from wp-admin while junctioned.
