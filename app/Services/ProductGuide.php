<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\File;

class ProductGuide
{
    private ?array $document = null;

    public function content(User $user): array
    {
        $source = $this->document ??= json_decode(File::get(resource_path('help/product-guide.json')), true, 512, JSON_THROW_ON_ERROR);
        $pages = [];
        foreach ($source['pages'] as $key => $page) {
            if (! $this->permits($user, $page['audience'] ?? 'all')) {
                continue;
            }
            $page['steps'] = array_values(array_filter($page['steps'], fn (array $step): bool => $this->permits($user, $step['audience'] ?? 'all')));
            $pages[$key] = $page;
        }
        $articles = [];
        foreach ($source['articles'] as $article) {
            if (! $this->permits($user, $article['audience'] ?? 'all')) {
                continue;
            }
            $article['actions'] = array_values(array_filter($article['actions'], fn (array $action): bool => isset($pages[$action['page']])));
            $articles[] = $article;
        }

        return ['version' => $source['version'], 'welcome' => $source['welcome'], 'pages' => $pages, 'articles' => $articles];
    }

    public function page(User $user, string $key): array
    {
        $source = $this->document ??= json_decode(File::get(resource_path('help/product-guide.json')), true, 512, JSON_THROW_ON_ERROR);
        abort_unless(isset($source['pages'][$key]), 404, 'Esta página de ajuda não está disponível.');
        $content = $this->content($user);
        abort_unless(isset($content['pages'][$key]), 403, 'Esta orientação não está disponível para seu perfil.');

        return ['version' => $content['version'], ...$content['pages'][$key]];
    }

    private function permits(User $user, string $audience): bool
    {
        return match ($audience) {
            'all' => true,
            'authors' => $user->role !== 'viewer',
            'admins' => $user->canAdministerUsers(),
            default => false,
        };
    }
}
