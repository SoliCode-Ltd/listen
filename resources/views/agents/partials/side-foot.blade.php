{{-- The foot of the Agents pages' side menu, as in the studio: (on an agent's page) the way back to all the
     agents, then help and the legal pages. Hidden on tablets and phones, where the menu is a strip of tabs. --}}
<div class="ag-side-foot">
  @if($back ?? false)
    <a class="ag-side-link" href="{{ route('voice-agents.index') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M2.8 20a6.4 6.4 0 0 1 12.4 0M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 14a6.4 6.4 0 0 1 2.7 6"/></svg>{{ __('agents.all_agents') }}</a>
  @endif
  <a class="ag-side-link" href="{{ route('contact') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.6 9.3a2.5 2.5 0 0 1 4.8.9c0 1.7-2.4 2.2-2.4 3.6M12 17h.01"/></svg>{{ __('ui.dashboard.help_link') }}</a>
  <div class="ag-side-legal">
    <a href="{{ route('developers') }}">{{ __('developers.footer_link') }}</a>
    <a href="{{ route('legal.privacy') }}">{{ __('ui.footer.privacy') }}</a>
    <a href="{{ route('legal.terms') }}">{{ __('ui.footer.terms') }}</a>
  </div>
</div>
