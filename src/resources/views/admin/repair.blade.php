@extends('layouts.admin')

@section('title')
    Anti-DDoS y Optimización
@endsection

@section('content-header')
    <h1>Anti-DDoS y Optimización <small>v{{ $version }}</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Anti-DDoS y Optimización</li>
    </ol>
@endsection

@section('content')
    @php
        $repairCmd = 'cd ' . base_path() . ' && php artisan tinker --execute=\'DB::table("migrations")->where("migration","like","%antiddos%")->delete();\' && php artisan migrate --force';
    @endphp
    <div class="row">
        <div class="col-xs-12">
            <div class="box box-danger">
                <div class="box-header with-border">
                    <h3 class="box-title">Faltan tablas de la base de datos</h3>
                </div>
                <div class="box-body">
                    <p>La extensión está instalada, pero estas tablas no existen en la base de datos, así que no puede abrir su pantalla ni sincronizar bloqueos con los nodos:</p>
                    <p>@foreach ($missing as $table)<code>{{ $table }}</code> @endforeach</p>
                    <p>Ejecuta esto como root en el servidor del panel. Vuelve a crear las tablas (los ajustes empezarán de cero) y no toca nada más:</p>
                    <pre style="white-space: pre-wrap;">{{ $repairCmd }}</pre>
                    <p class="text-muted" style="margin-bottom:0;">Después recarga esta página.</p>
                </div>
            </div>
        </div>
    </div>
@endsection
