<?php
declare(strict_types=1);
namespace App\Core;

final class SessionStore
{
    /** Use PHP's Redis session handler when explicitly configured; never silently fall back. */
    public static function configure(): void
    {
        $driver=env('SESSION_DRIVER','files');
        if($driver==='files'){return;}
        if($driver!=='redis'){throw new \RuntimeException('Unsupported SESSION_DRIVER.');}
        if(!extension_loaded('redis')){throw new \RuntimeException('Redis sessions require the phpredis PHP extension.');}
        $scheme=env('REDIS_TLS','0')==='1'?'tls':'tcp';
        $host=env('REDIS_HOST','127.0.0.1');$port=filter_var(env('REDIS_PORT','6379'),FILTER_VALIDATE_INT);
        if(!$host||!preg_match('/^[a-zA-Z0-9.\-]+$/',$host)||!$port){throw new \RuntimeException('Invalid Redis host or port.');}
        $options=['database'=>(int)env('REDIS_SESSION_DB','1'),'prefix'=>'tlmti:session:','timeout'=>2,'read_timeout'=>2];
        $password=env('REDIS_PASSWORD','');if($password!==''){$options['auth']=$password;}
        ini_set('session.save_handler','redis');
        ini_set('session.save_path',$scheme.'://'.$host.':'.$port.'?'.http_build_query($options));
        ini_set('redis.session.locking_enabled','1');
        ini_set('redis.session.lock_retries','100');
        ini_set('redis.session.lock_wait_time','20000');
        ini_set('session.gc_maxlifetime',(string)(max(5,(int)env('SESSION_TTL_MINUTES','120'))*60));
    }
}
