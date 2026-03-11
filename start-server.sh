#!/bin/bash
unset DB_CONNECTION
unset DB_HOST
unset DB_PORT
unset DB_DATABASE
unset DB_USERNAME
unset DB_PASSWORD
export DB_CONNECTION=sqlite
cd /workspace
php artisan serve --host=0.0.0.0 --port=8000
