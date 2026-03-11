#!/bin/bash
export DB_CONNECTION=sqlite
export DB_DATABASE=/workspace/database/database.sqlite
cd /workspace
exec php artisan serve --host=0.0.0.0 --port=8000
