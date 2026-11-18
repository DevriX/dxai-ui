# Local WP

## Create the site

1. Open Local (`C:\Program Files (x86)\Local\Local.exe`).
2. **+** → site name `DX-UI` (domain `dx-ui.local`).
3. Custom environment: PHP **8.2**, nginx, **MySQL**.
4. WordPress user `admin` / `dxuiadmin` (no special characters).
5. Approve UAC for hosts + SSL. **Trust** the certificate.

Or, with Local running, GraphQL `addSite` (token in `%APPDATA%\Local\graphql-connection-info.json` — do not commit it).

## Link the plugin

```bat
mklink /J "C:\Users\DevriX\Local Sites\DX-UI\app\public\wp-content\plugins\dx-ui" "C:\Users\DevriX\Documents\DX-UI"
```

Then activate:

```text
wp plugin activate dx-ui
```

Admin: `https://dx-ui.local/wp-admin/admin.php?page=dx-ui`

Do not update this plugin from wp-admin while junctioned.
