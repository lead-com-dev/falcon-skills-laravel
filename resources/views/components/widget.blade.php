@if ($signedIn)
<script @if ($scriptNonce) nonce="{{ $scriptNonce }}" @endif>
    window.__falconSkillsPendingIdentityProvider = async function () {
        const response = await fetch(@js($contextUrl), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });

        if (! response.ok) {
            return '';
        }

        const body = await response.json();

        return typeof body.token === 'string' ? body.token : '';
    };
    window.FalconSkills?.identify(window.__falconSkillsPendingIdentityProvider);
</script>
@endif
<script src="{{ $scriptUrl }}" data-falcon-skills-key="{{ $key }}" data-falcon-skills-company="{{ $company }}" @if ($scriptNonce) nonce="{{ $scriptNonce }}" @endif {{ $attributes }} async></script>
