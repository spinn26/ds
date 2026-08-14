<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Корневая страница SPA отдаётся без ошибок.
     *
     * withoutVite(): шаблон зовёт @vite, а тот требует собранный
     * public/build/manifest.json. Локально он есть после npm run build, в CI —
     * нет, и тест падал 500-й на отсутствующем манифесте. Собирать фронт ради
     * одного смоука дорого, а проверяем мы здесь бутстрап приложения и роут,
     * не сборку ассетов.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $this->withoutVite();

        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
