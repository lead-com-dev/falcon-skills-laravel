<?php

namespace FalconSkills\View\Components;

use FalconSkills\UserContext;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Vite;
use Illuminate\View\Component;

/**
 * <x-falcon-skills::widget /> loads the Falcon Skills widget. For a signed-in
 * visitor it also hands the widget a function that fetches a fresh signed
 * context, so the widget knows who is asking. Extra attributes (such as
 * data-falcon-skills-page) go on the widget's script tag.
 */
class Widget extends Component
{
    public function __construct(public ?string $nonce = null)
    {
    }

    public function render(): View|string
    {
        $context = app(UserContext::class);
        $key = (string) config('falcon-skills.widget.key');
        $company = (string) config('falcon-skills.company');

        if ($key === '' || $company === '') {
            return '';
        }

        return view('falcon-skills::components.widget', [
            'key' => $key,
            'company' => $company,
            'scriptUrl' => (string) config('falcon-skills.widget.script_url'),
            'contextUrl' => route('falcon-skills.context', [], false),
            'signedIn' => $context->isConfigured() && auth()->guard(config('falcon-skills.widget.guard'))->check(),
            'scriptNonce' => $this->nonce ?? (class_exists(Vite::class) ? app(Vite::class)->cspNonce() : null),
        ]);
    }
}
