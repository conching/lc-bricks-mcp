# dev/ — development-only assets (never shipped)

Everything in this directory is excluded from the distributable ZIP via
`.distignore` and must **not** be deployed to production.

## wp-env-fixes.php

A WordPress **must-use plugin** for local [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
/ Docker development only. It does two things, both gated behind
`wp_get_environment_type() === 'local'`:

1. Rewrites loopback HTTP requests from the host-mapped port (e.g. `8888`) to
   port `80`, which is what Apache listens on inside the wp-env container.
2. **Enables Application Passwords over plain HTTP**
   (`add_filter( 'wp_is_application_passwords_available', '__return_true' )`).

Item 2 is the reason this file is quarantined here: on any host WordPress
reports as `local`, it removes the HTTPS requirement for Application
Passwords. That is fine for a throwaway local box, but a security downgrade
anywhere else. It is therefore **excluded from the production build** and
lives here for local use only.

### Using it locally

Copy (or symlink) it into your local site's `wp-content/mu-plugins/`
directory:

```bash
cp dev/wp-env-fixes.php /path/to/wp-content/mu-plugins/wp-env-fixes.php
```

Must-use plugins load automatically — no activation needed. Delete it when
you are done, or if you are not using wp-env/Docker.
