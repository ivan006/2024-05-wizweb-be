<?php
namespace QuicklistsOrmApi;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

abstract class OrmApiBaseModel extends Model
{
    public function relationships(){
        return [];
    }

    public function rules()
    {
        return [];
    }

    public function listable()
    {
        return [];
    }

    public function creatable($payload)
    {
        return true;
    }

    public function readable($record)
    {
        return true;
    }

    public function updatable($payload, $record)
    {
        return true;
    }

    public function deletable($record)
    {
        return true;
    }

    public function fieldExtraInfo()
    {
        return [];
    }
}
