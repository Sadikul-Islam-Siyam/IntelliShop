# Staging setup

The Day-1 pack is staging-ready but does not provision a cloud environment. Deploy Laravel, MySQL, and the analytics HTTP service on a private network. The browser sends requests only to Laravel under `/api/v1`; Laravel reaches analytics via `ANALYTICS_SERVICE_URL`.

Set production secrets through the deployment secret manager, including `APP_KEY`, database credentials, and `ANALYTICS_SERVICE_TOKEN`. Set `ANALYTICS_SERVICE_URL` to the private analytics service URL and use HTTPS at the ingress. Run `php artisan migrate --force` during release, build frontend assets with `npm run build`, and expose Laravel's `/up` health endpoint. Never commit environment secrets.

For local Docker Compose, the default analytics URL is `http://analytics:8000`; the analytics container is supplied by the analytics-track deployment when that service is added.
