@extends('plex-recommender::layout')

@section('head')
    <meta http-equiv="refresh" content="1;url={{ $next }}">
@endsection

@section('content')
    <h1>Venter på Plex …</h1>
    <p>Godkend login i Plex-vinduet. Siden opdaterer automatisk.</p>
@endsection
