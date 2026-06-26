<?php

namespace Elveneek;

enum SchemaMode: string
{
    case Strict = 'strict';
    case Suggest = 'suggest';
    case Evolve = 'evolve';
}
