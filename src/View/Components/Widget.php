<?php

namespace FalconSkills\View\Components;

use FalconSkills\UserContext;
use FalconSkills\View\FailSafeView;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Vite;
use Illuminate\View\Component;

/**
 * <x-falcon-skills::widget /> loads the Falcon widget. For a signed-in
 * visitor it also hands the widget a function that fetches a fresh signed
 * context, so the widget knows who is asking. Extra attributes (such as
 * data-falcon-skills-page) go on the widget's script tag. Any error while
 * rendering is reported and the widget renders nothing, so the page still loads.
 */
class Widget extends Component
{
    public function __construct(public ?string $nonce = null)
    {
    }

    public function render(): View|string
    {
        $key = (string) config('falcon-skills.widget.key');
        $company = (string) config('falcon-skills.company');

        if ($key === '' || $company === '') {
            return '';
        }

        return new FailSafeView('falcon-skills::components.widget', fn (): View => view('falcon-skills::components.widget', [
            'key' => $key,
            'company' => $company,
            'scriptUrl' => (string) config('falcon-skills.widget.script_url'),
            'contextUrl' => route('falcon-skills.context', [], false),
            'signedIn' => app(UserContext::class)->isConfigured() && auth()->guard(config('falcon-skills.widget.guard'))->check(),
            'scriptNonce' => $this->nonce ?? (class_exists(Vite::class) ? app(Vite::class)->cspNonce() : null),
        ]));
    }
}
