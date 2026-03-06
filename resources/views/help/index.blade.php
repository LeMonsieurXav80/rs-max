@extends('layouts.app')

@section('title', 'Aide')

@section('content')
    <div class="max-w-3xl">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 lg:p-8">
            <div class="prose prose-sm prose-indigo max-w-none">
                {!! $content !!}
            </div>
        </div>
    </div>
@endsection
