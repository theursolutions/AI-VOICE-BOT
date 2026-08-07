@extends('errors.layout')

@section('title', 'Access denied')
@section('code', '403')
@section('heading', 'You don’t have access to this')
@section('message')
{{ ($exception && $exception->getMessage()) ? $exception->getMessage() : 'This section isn’t available for your account. If you think this is a mistake, ask your workspace owner to grant access.' }}
@endsection
