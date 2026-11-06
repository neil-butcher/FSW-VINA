@extends('layouts.survey')

@section('content')
    <div class="card">
        <div class="card-body">
            <h1>{{ __('Find your name') }}</h1>
            <p>{{ __('Enter your name and the five-letter secret word given to you by the organiser.') }}</p>
            <form method="POST" action="{{ route('roster.claim.submit') }}">
                @csrf
                <label for="name">{{ __('Your name') }}</label>
                <input id="name" name="name" type="text" class="form-control" value="{{ old('name') }}" autocomplete="off" required maxlength="255">
                @error('name') <p class="text-danger">{{ $message }}</p> @enderror
                <label for="secret_word">{{ __('Secret word') }}</label>
                <input id="secret_word" name="secret_word" type="password" class="form-control" autocomplete="off" required minlength="5" maxlength="5" pattern="[A-Za-z]{5}" autocapitalize="off" spellcheck="false">
                @error('secret_word') <p class="text-danger">{{ $message }}</p> @enderror
                <button class="btn btn-primary mt-3" type="submit">{{ __('Continue') }}</button>
            </form>
        </div>
    </div>
@endsection
