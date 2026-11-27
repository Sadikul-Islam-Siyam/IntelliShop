# IntelliShop

Intelligent Super Shop Management and Decision-Support System

## Project

IntelliShop is a supermarket management and decision-support
system developed as a KUET CSE 3200 System Development Project.

## Branch Structure

- `main` — stable release-ready code
- `dev` — shared development/integration branch
- `feature/*` — individual development branches

All feature work must be submitted through Pull Requests to `dev`.

## Local dataset

The repository includes a bundled synthetic five-year supermarket dataset at
`database/datasets/intellishop_dataset_csv.zip`. On the first local startup,
`run.sh` or `docker compose up` imports it after running migrations. The import
replaces the existing business data and clears old login sessions. It runs once
per database; run `php artisan intellishop:import-local-dataset --refresh` to
deliberately replace the data again.

All bundled user accounts use the password `password`. The dataset is synthetic
and does not contain real customer or transaction data. Dataset importing
is restricted to `APP_ENV=local`, and Docker deployment images exclude the ZIP.
