<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('WebUser', function (Blueprint $table) {
            $table->string('workField', 255)->nullable();
            $table->string('salesExperience', 32)->nullable();
            $table->text('financeExperience')->nullable();
            $table->string('hasPotentialClients', 16)->nullable();
            $table->string('potentialClientsCount', 16)->nullable();
            $table->string('currentIncome', 128)->nullable();
            $table->string('weeklyHours', 32)->nullable();
            $table->text('incomeFactors')->nullable();
            $table->timestamp('questionnaireCompletedAt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('WebUser', function (Blueprint $table) {
            $table->dropColumn([
                'workField',
                'salesExperience',
                'financeExperience',
                'hasPotentialClients',
                'potentialClientsCount',
                'currentIncome',
                'weeklyHours',
                'incomeFactors',
                'questionnaireCompletedAt',
            ]);
        });
    }
};
