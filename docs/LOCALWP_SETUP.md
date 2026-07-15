# LocalWP Setup Guide

This guide configures your LocalWP environment for stable MCP connections. Bricks MCP uses Server-Sent Events (SSE) for streaming, which requires PHP execution time and Nginx buffering adjustments.

## Prerequisites

- LocalWP installed with a running WordPress site
- Bricks MCP plugin activated
- Site using Nginx (LocalWP default)

## 1. Find Your Site's Configuration Directory

LocalWP stores per-site server configuration under the site root:

| OS | Site root |
|----|-----------|
| macOS | `~/Local Sites/<site-name>/` |
| Windows | `C:\Users\<username>\Local Sites\<site-name>\` |
| Linux | `~/Local Sites/<site-name>/` |

All configuration files are inside the `conf/` subdirectory.

## 2. Increase PHP-FPM Request Timeout

The PHP-FPM `request_terminate_timeout` controls how long a PHP process can run before being killed. The default is often 30 seconds, which is too short for SSE streams.

**File:** `conf/php/php-fpm.conf`

> **Note:** If `php-fpm.conf` is not at that path, look for any `.conf` file inside `conf/php/` that contains a `[www]` section header. Newer LocalWP versions with PHP 8.x may use a versioned subdirectory like `conf/php/8.x/php-fpm.d/www.conf`.

Find the `[www]` section and add or update:

```ini
request_terminate_timeout = 300
```

Also set `max_execution_time` in `conf/php/php.ini` (create the file if it doesn't exist):

```ini
max_execution_time = 300
```

## 3. Disable Nginx FastCGI Buffering

SSE streams must not be buffered by Nginx, or events will be delayed until the buffer fills.

**File:** `conf/nginx/site.conf.hbs`

> **Important:** Edit the `.hbs` file, not `site.conf`. LocalWP regenerates `site.conf` from the `.hbs` template on restart, so direct edits to `site.conf` will be lost.

Inside the `server { }` block (before or after the existing `location` directives), add:

```nginx
fastcgi_buffering off;
```

## 4. Restart the Site

In LocalWP, right-click your site and select **Restart** (or click the Stop/Start toggle). This applies both the PHP-FPM and Nginx configuration changes.

## 5. Verify

1. Open **Settings > Bricks MCP** in WordPress admin
2. Run the diagnostic checks — **PHP Execution Time** should show a green pass
3. Test an MCP connection with a long-running tool call (e.g., listing many posts)

If the diagnostic still shows a warning, double-check that you edited the correct files and restarted the site.

## Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| Diagnostic still shows warning after restart | Edited wrong PHP config file | Search for the file containing `[www]` in `conf/php/` |
| SSE stream drops after ~30 seconds | `request_terminate_timeout` not applied | Verify the directive is inside the `[www]` section, not outside it |
| Events arrive in bursts instead of real-time | Nginx buffering still enabled | Confirm `fastcgi_buffering off;` is in `site.conf.hbs` (not `site.conf`) |
| Config changes lost after restart | Edited `site.conf` instead of `site.conf.hbs` | Move the directive to the `.hbs` file |
