<div wire:ignore.self class="modal fade" id="claimWordModal" tabindex="-1" aria-labelledby="claimWordTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form wire:submit="saveClaimWord">
                <div class="modal-header">
                    <h5 class="modal-title" id="claimWordTitle">{{ __('Set secret word') }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}"></button>
                </div>
                <div class="modal-body">
                    <p>{{ __('Choose a five-letter word and give it privately to this participant. Changing it ends their active session.') }}</p>
                    <label for="claimWord">{{ __('Secret word') }}</label>
                    <input id="claimWord" wire:model="claimWord" type="password" class="form-control" autocomplete="off" required minlength="5" maxlength="5" pattern="[A-Za-z]{5}" spellcheck="false">
                    @error('claimWord') <p class="text-danger">{{ $message }}</p> @enderror
                    @error('claimStudentId') <p class="text-danger">{{ $message }}</p> @enderror
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">{{ __('Close') }}</button>
                    <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
