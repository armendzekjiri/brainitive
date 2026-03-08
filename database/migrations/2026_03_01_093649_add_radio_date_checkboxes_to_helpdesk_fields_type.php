<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Allow new field types: radio, date, checkboxes (options: select, radio, checkboxes).
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE helpdesk_fields MODIFY COLUMN type VARCHAR(50) NOT NULL");
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE helpdesk_fields DROP CONSTRAINT IF EXISTS helpdesk_fields_type_check');
            DB::statement("ALTER TABLE helpdesk_fields ADD CONSTRAINT helpdesk_fields_type_check CHECK (type::text = ANY (ARRAY['text', 'rich_text', 'select', 'checkbox', 'file', 'number', 'radio', 'date', 'checkboxes']::text[]))");
            return;
        }

        // SQLite: doesn't support DROP CONSTRAINT; check constraints are not enforced at runtime, so nothing needed.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE helpdesk_fields MODIFY COLUMN type ENUM('text','rich_text','select','checkbox','file','number') NOT NULL");
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE helpdesk_fields DROP CONSTRAINT IF EXISTS helpdesk_fields_type_check');
            DB::statement("ALTER TABLE helpdesk_fields ADD CONSTRAINT helpdesk_fields_type_check CHECK (type::text = ANY (ARRAY['text', 'rich_text', 'select', 'checkbox', 'file', 'number']::text[]))");
        }
    }
};
