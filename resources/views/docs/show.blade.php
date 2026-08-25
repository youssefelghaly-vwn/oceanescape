@extends('docs.layout')

@section('title', $document['name'] . ' — Documentation')

@section('content')

    {!! $content !!}

@endsection