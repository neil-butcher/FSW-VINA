@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>{{ __('Import survey and roster') }}</h1>
        <p>{{ __('Create a new survey and its participants from a CSV file. This uses the questionnaire already installed in the application.') }}</p>
        <p>{{ __('Put the new survey code on the first line, followed by a blank line. Add one participant per line as name,secret word, without a header. Names must be unique and words must contain exactly five letters (A-Z).') }}</p>
        <p>{{ __('Keep the file private: it contains participant names and secret words.') }}</p>
        <p><a href="{{ route('survey.import.example') }}">{{ __('Download example CSV') }}</a></p>
        <form method="POST" action="{{ route('survey.import.store') }}" enctype="multipart/form-data">
            @csrf
            <label for="roster">{{ __('Roster CSV file') }}</label>
            <input id="roster" name="roster" type="file" accept=".csv,text/csv" class="form-control" required>
            @error('roster') <p class="text-danger" role="alert">{{ $message }}</p> @enderror
            <button type="submit" class="btn btn-primary mt-3">{{ __('Import') }}</button>
            <a href="{{ url('/surveytable') }}" class="btn btn-outline-secondary mt-3">{{ __('Cancel') }}</a>
        </form>
    </div>
@endsection
