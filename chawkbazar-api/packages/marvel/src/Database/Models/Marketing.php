<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class Marketing extends Model
{
    public $timestamps=false;
    protected $table="marketing_images";
    protected $fillable=["text_position",'url','area','text'];


}
