<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppTemplate;
use Illuminate\Validation\ValidationException;

class TemplateService
{
    public const VARIABLES = ['nama_pelanggan', 'nama_pam', 'id_pelanggan', 'rt', 'rincian_tunggakan', 'total_tunggakan', 'rekening_pembayaran', 'kontak_bendahara'];

    public function variables(string $body): array
    {
        preg_match_all('/\{([^{}]+)\}/u', $body, $matches);
        $unknown = array_values(array_diff($matches[1], self::VARIABLES));
        $remainder = preg_replace('/\{([^{}]+)\}/u', '', $body);
        if ($unknown || str_contains($remainder, '{') || str_contains($remainder, '}')) {
            throw ValidationException::withMessages(['body' => 'Variabel template tidak dikenal atau tidak valid: '.implode(', ', $unknown ?: ['format kurung kurawal']).'.']);
        }
        if (trim($body) === '' || mb_strlen($this->compile($body)) > 1024) {
            throw ValidationException::withMessages(['body' => 'Isi template wajib diisi dan maksimal 1.024 karakter setelah dikompilasi.']);
        }

        return $matches[1];
    }

    private function compile(string $body): string
    {
        $position = 0;

        return preg_replace_callback('/\{([^{}]+)\}/u', function () use (&$position) {
            return '{{'.++$position.'}}';
        }, $body);
    }

    public function providerBody(string $body): string
    {
        $this->variables($body);

        return $this->compile($body);
    }

    public static function bodyHash(WhatsAppTemplate $template): string
    {
        return hash('sha256', json_encode([$template->provider_name, $template->language, $template->body, app(self::class)->providerBody($template->body)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function render(WhatsAppTemplate $template, array $data): array
    {
        $variables = $this->variables($template->body);
        $parameters = [];
        $errors = [];
        foreach ($variables as $variable) {
            // The preview uses exactly the values submitted to Meta. Body text
            // parameters cannot contain tabs or newlines.
            $value = trim(preg_replace('/\s+/u', ' ', (string) ($data[$variable] ?? '')));
            if ($value === '') {
                $errors[$variable] = 'Data wajib belum tersedia: {'.$variable.'}.';
            }
            $parameters[] = $value;
        }
        $position = 0;
        $body = preg_replace_callback('/\{([^{}]+)\}/u', function () use (&$position, $parameters) {
            $value = $parameters[$position++];

            return $value !== '' ? $value : '[DATA BELUM TERSEDIA]';
        }, $template->body);

        return ['body' => $body, 'parameters' => $parameters, 'errors' => array_values($errors)];
    }
}
