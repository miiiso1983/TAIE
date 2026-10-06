<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', fn (Blueprint $t) => $t->longText('response_encrypted')->nullable());
        DB::table('idempotency_keys')->whereNotNull('response_body')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('idempotency_keys')->where('id', $row->id)->update(['response_encrypted' => Crypt::encryptString($row->response_body), 'response_body' => null]);
            }
        });
    }

    public function down(): void
    {
        DB::table('idempotency_keys')->whereNotNull('response_encrypted')->orderBy('id')->chunkById(100, function ($rows) {
            foreach ($rows as $row) {
                DB::table('idempotency_keys')->where('id', $row->id)->update(['response_body' => Crypt::decryptString($row->response_encrypted)]);
            }
        });
        Schema::table('idempotency_keys', fn (Blueprint $t) => $t->dropColumn('response_encrypted'));
    }
};
