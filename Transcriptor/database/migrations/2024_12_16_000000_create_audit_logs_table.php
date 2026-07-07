<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // User information - nullable to preserve logs even after user deletion
            $table->unsignedBigInteger('user_id')->nullable()->comment('User who performed the action');
            $table->string('user_email')->nullable()->comment('Email of user at time of action');
            $table->string('user_name')->nullable()->comment('Name of user at time of action');
            $table->string('user_type')->nullable()->comment('Type/role of user');

            // Action details
            $table->string('event')->comment('Type of event (created, updated, deleted, etc.)');
            $table->string('auditable_type')->nullable()->comment('Model class name');
            $table->unsignedBigInteger('auditable_id')->nullable()->comment('Model ID');

            // Request context
            $table->string('ip_address', 45)->nullable()->comment('IP address of request');
            $table->string('user_agent')->nullable()->comment('User agent string');
            $table->string('url')->nullable()->comment('Request URL');
            $table->string('http_method', 10)->nullable()->comment('HTTP method (GET, POST, etc.)');

            // Data tracking
            $table->json('old_values')->nullable()->comment('Previous values before change');
            $table->json('new_values')->nullable()->comment('New values after change');
            $table->json('metadata')->nullable()->comment('Additional contextual data');

            // COA Compliance fields
            $table->string('transaction_id')->nullable()->comment('Unique transaction identifier');
            $table->string('session_id')->nullable()->comment('Session identifier');
            $table->text('description')->nullable()->comment('Human-readable description of action');
            $table->enum('severity', ['low', 'medium', 'high', 'critical'])->default('low');
            $table->string('module')->nullable()->comment('System module/feature');

            // Timestamps - immutable
            $table->timestamp('created_at')->useCurrent()->comment('When the log was created');

            // Indexes for performance
            $table->index('user_id');
            $table->index('event');
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('created_at');
            $table->index('ip_address');
            $table->index('transaction_id');
        });

        // Add database-level protection against updates and deletes
        DB::statement('
            CREATE TRIGGER prevent_audit_log_update
            BEFORE UPDATE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE "45000"
                SET MESSAGE_TEXT = "Audit logs cannot be modified - COA Compliance";
            END
        ');

        DB::statement('
            CREATE TRIGGER prevent_audit_log_delete
            BEFORE DELETE ON audit_logs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE "45000"
                SET MESSAGE_TEXT = "Audit logs cannot be deleted - COA Compliance";
            END
        ');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS prevent_audit_log_update');
        DB::statement('DROP TRIGGER IF EXISTS prevent_audit_log_delete');
        Schema::dropIfExists('audit_logs');
    }
};
