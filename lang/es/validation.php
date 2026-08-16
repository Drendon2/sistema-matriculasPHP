<?php

/**
 * Mensajes del validador en espanol.
 *
 * Laravel no trae traducciones: sin este archivo, un campo obligatorio sin
 * llenar responde "The nombre completo field is required." en un formulario que
 * esta entero en espanol. Los controladores ya traen mensajes propios para los
 * casos que necesitan una explicacion concreta; esto cubre todo lo demas.
 *
 * `:attribute` lo rellena la lista `attributes` de mas abajo, o el nombre que el
 * controlador pase como tercer argumento de `validate()`.
 */

return [

    'accepted' => 'Debes aceptar :attribute.',
    'accepted_if' => 'Debes aceptar :attribute cuando :other es :value.',
    'active_url' => ':attribute no es una URL válida.',
    'after' => ':attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => ':attribute debe ser una fecha posterior o igual a :date.',
    'alpha' => ':attribute solo puede contener letras.',
    'alpha_dash' => ':attribute solo puede contener letras, números, guiones y guiones bajos.',
    'alpha_num' => ':attribute solo puede contener letras y números.',
    'array' => ':attribute debe ser una lista.',
    'before' => ':attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => ':attribute debe ser una fecha anterior o igual a :date.',
    'between' => [
        'array' => ':attribute debe tener entre :min y :max elementos.',
        'file' => ':attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => ':attribute debe estar entre :min y :max.',
        'string' => ':attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => ':attribute solo puede ser verdadero o falso.',
    'confirmed' => ':attribute no coincide con su confirmación.',
    'current_password' => 'La contraseña es incorrecta.',
    'date' => ':attribute no es una fecha válida.',
    'date_equals' => ':attribute debe ser una fecha igual a :date.',
    'date_format' => ':attribute no corresponde al formato :format.',
    'declined' => 'Debes rechazar :attribute.',
    'different' => ':attribute y :other deben ser distintos.',
    'digits' => ':attribute debe tener :digits dígitos.',
    'digits_between' => ':attribute debe tener entre :min y :max dígitos.',
    'dimensions' => ':attribute tiene unas dimensiones de imagen que no se admiten.',
    'distinct' => ':attribute tiene un valor repetido.',
    'doesnt_end_with' => ':attribute no puede terminar por ninguno de estos valores: :values.',
    'doesnt_start_with' => ':attribute no puede empezar por ninguno de estos valores: :values.',
    'email' => ':attribute no es un correo electrónico válido.',
    'ends_with' => ':attribute debe terminar por uno de estos valores: :values.',
    'enum' => 'El valor de :attribute no es válido.',
    'exists' => 'El valor de :attribute no existe.',
    'file' => ':attribute debe ser un archivo.',
    'filled' => ':attribute no puede quedar vacío.',
    'gt' => [
        'array' => ':attribute debe tener más de :value elementos.',
        'file' => ':attribute debe pesar más de :value kilobytes.',
        'numeric' => ':attribute debe ser mayor que :value.',
        'string' => ':attribute debe tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => ':attribute debe tener :value elementos o más.',
        'file' => ':attribute debe pesar :value kilobytes o más.',
        'numeric' => ':attribute debe ser como mínimo :value.',
        'string' => ':attribute debe tener :value caracteres o más.',
    ],
    'image' => ':attribute debe ser una imagen.',
    'in' => 'El valor de :attribute no es válido.',
    'in_array' => 'El valor de :attribute no está entre los admitidos.',
    'integer' => ':attribute debe ser un número entero.',
    'ip' => ':attribute debe ser una dirección IP válida.',
    'ipv4' => ':attribute debe ser una dirección IPv4 válida.',
    'ipv6' => ':attribute debe ser una dirección IPv6 válida.',
    'json' => ':attribute debe ser un texto JSON válido.',
    'lowercase' => ':attribute debe ir en minúsculas.',
    'lt' => [
        'array' => ':attribute debe tener menos de :value elementos.',
        'file' => ':attribute debe pesar menos de :value kilobytes.',
        'numeric' => ':attribute debe ser menor que :value.',
        'string' => ':attribute debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => ':attribute no puede tener más de :value elementos.',
        'file' => ':attribute no puede pesar más de :value kilobytes.',
        'numeric' => ':attribute debe ser como máximo :value.',
        'string' => ':attribute no puede tener más de :value caracteres.',
    ],
    'max' => [
        'array' => ':attribute no puede tener más de :max elementos.',
        'file' => ':attribute no puede pesar más de :max kilobytes.',
        'numeric' => ':attribute no puede ser mayor que :max.',
        'string' => ':attribute no puede tener más de :max caracteres.',
    ],
    'mimes' => ':attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => ':attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'array' => ':attribute debe tener al menos :min elementos.',
        'file' => ':attribute debe pesar al menos :min kilobytes.',
        'numeric' => ':attribute debe ser al menos :min.',
        'string' => ':attribute debe tener al menos :min caracteres.',
    ],
    'multiple_of' => ':attribute debe ser múltiplo de :value.',
    'not_in' => 'El valor de :attribute no es válido.',
    'not_regex' => 'El formato de :attribute no es válido.',
    'numeric' => ':attribute debe ser un número.',
    'present' => ':attribute debe estar presente.',
    'prohibited' => ':attribute no se admite.',
    'prohibited_if' => ':attribute no se admite cuando :other es :value.',
    'prohibited_unless' => ':attribute no se admite salvo que :other esté entre :values.',
    'regex' => 'El formato de :attribute no es válido.',
    'required' => 'Falta :attribute.',
    'required_array_keys' => 'A :attribute le faltan entradas: :values.',
    'required_if' => 'Falta :attribute cuando :other es :value.',
    'required_if_accepted' => 'Falta :attribute cuando se acepta :other.',
    'required_unless' => 'Falta :attribute salvo que :other esté entre :values.',
    'required_with' => 'Falta :attribute cuando hay :values.',
    'required_with_all' => 'Falta :attribute cuando hay :values.',
    'required_without' => 'Falta :attribute cuando no hay :values.',
    'required_without_all' => 'Falta :attribute cuando no hay ninguno de :values.',
    'same' => ':attribute y :other deben coincidir.',
    'size' => [
        'array' => ':attribute debe tener :size elementos.',
        'file' => ':attribute debe pesar :size kilobytes.',
        'numeric' => ':attribute debe ser :size.',
        'string' => ':attribute debe tener :size caracteres.',
    ],
    'starts_with' => ':attribute debe empezar por uno de estos valores: :values.',
    'string' => ':attribute debe ser texto.',
    'timezone' => ':attribute debe ser una zona horaria válida.',
    'unique' => 'Ya existe un registro con ese :attribute.',
    'uploaded' => 'No se pudo subir :attribute.',
    'uppercase' => ':attribute debe ir en mayúsculas.',
    'url' => ':attribute debe ser una URL válida.',
    'uuid' => ':attribute debe ser un UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Mensajes propios de un campo
    |--------------------------------------------------------------------------
    |
    | Van aqui los que no dependen de la pantalla. Los que si dependen —"Ya
    | existe una cuenta con ese nombre de usuario"— se quedan junto a su
    | formulario, que es donde se entiende por que estan redactados asi.
    |
    */

    'custom' => [
        'password' => [
            'confirmed' => 'Las contraseñas no coinciden.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Nombres de los campos
    |--------------------------------------------------------------------------
    |
    | Con que se rellena `:attribute`. Sin esto sale el nombre de la columna tal
    | cual, y "El campo nombre_completo es obligatorio" no es texto para nadie.
    |
    */

    'attributes' => [
        'acudiente_nombre' => 'nombre del acudiente',
        'acudiente_telefono' => 'teléfono del acudiente',
        'color_acento' => 'color de acento',
        'copia_documento' => 'copia del documento',
        'cupo_maximo' => 'cupo máximo',
        'documento_identidad' => 'documento de identidad',
        'fecha_fin' => 'fecha de fin',
        'fecha_inicio' => 'fecha de inicio',
        'fecha_nacimiento' => 'fecha de nacimiento',
        'foto_perfil' => 'foto de perfil',
        'limite_promotorias_por_periodo' => 'límite de promotorías por periodo',
        'nombre_completo' => 'nombre completo',
        'nombre_institucion' => 'nombre de la institución',
        'password' => 'contraseña',
        'salon' => 'salón',
        'telefono' => 'teléfono',
        'username' => 'usuario',
    ],

];
