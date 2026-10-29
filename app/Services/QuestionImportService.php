<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\{Authorization as A,Database as DB,Validator as V};

final class QuestionImportService
{
    public const HEADERS=['question_en','question_hi','option_a_en','option_a_hi','option_b_en','option_b_hi','option_c_en','option_c_hi','option_d_en','option_d_hi','correct_option','default_marks','difficulty','status'];
    private static function xml(string $text): \SimpleXMLElement
    {
        if(strlen($text)>8388608||preg_match('/<!DOCTYPE|<!ENTITY/i',$text))throw new \DomainException('Unsafe or oversized worksheet XML.');
        $old=libxml_use_internal_errors(true);try{$xml=simplexml_load_string($text,\SimpleXMLElement::class,LIBXML_NONET|LIBXML_NOBLANKS);if($xml===false)throw new \DomainException('Invalid Excel XML.');return $xml;}finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    }
    public static function read(string $path,string $filename): array
    {
        if(!is_file($path)||filesize($path)>2097152||filesize($path)===0)throw new \DomainException('Upload an Excel or CSV file under 2 MB.');
        $ext=strtolower(pathinfo($filename,PATHINFO_EXTENSION));$rows=[];
        if($ext==='csv'){
            $h=fopen($path,'rb');try{while(($r=fgetcsv($h,65536,',','"',''))!==false){if(count($r)>14)throw new \DomainException('Unexpected spreadsheet columns.');if(array_filter($r,fn($v)=>trim((string)$v)!==''))$rows[]=$r;if(count($rows)>501)throw new \DomainException('Import up to 500 questions at a time.');}}finally{fclose($h);}
        }elseif($ext==='xlsx'){
            $zip=new \ZipArchive();if($zip->open($path)!==true)throw new \DomainException('Choose a valid XLSX workbook.');
            try{
                if($zip->numFiles>250)throw new \DomainException('Workbook has too many parts.');$size=0;
                for($i=0;$i<$zip->numFiles;$i++){$s=$zip->statIndex($i);$size+=(int)$s['size'];if($size>20971520||stripos($s['name'],'vbaProject')!==false||($s['encryption_method']??0)!==0)throw new \DomainException('Compressed, encrypted or macro workbooks are not supported.');}
                $wb=self::xml($zip->getFromName('xl/workbook.xml')?:'');$rels=self::xml($zip->getFromName('xl/_rels/workbook.xml.rels')?:'');$sheets=$wb->xpath('//*[local-name()="sheet"]');if(!$sheets)throw new \DomainException('Workbook has no worksheet.');
                $rid=(string)$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];$target=null;
                foreach($rels->xpath('//*[local-name()="Relationship"]') as $rel){if((string)$rel['Id']===$rid){if((string)$rel['TargetMode']==='External')throw new \DomainException('External worksheets are not supported.');$target=(string)$rel['Target'];}}
                if(!$target)throw new \DomainException('Worksheet relationship not found.');$target=str_starts_with($target,'/')?ltrim($target,'/'):'xl/'.$target;
                if(!preg_match('~^xl/worksheets/[A-Za-z0-9_.-]+\.xml$~',$target)||str_contains($target,'..'))throw new \DomainException('Unsafe worksheet path.');
                $strings=[];$shared=$zip->getFromName('xl/sharedStrings.xml');if($shared!==false){foreach(self::xml($shared)->xpath('//*[local-name()="si"]') as $si){$strings[]=implode('',array_map('strval',$si->xpath('.//*[local-name()="t"]')));if(count($strings)>10000)throw new \DomainException('Too many shared strings.');}}
                $sheet=self::xml($zip->getFromName($target)?:'');foreach($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $r){$values=array_fill(0,14,'');foreach($r->xpath('./*[local-name()="c"]') as $cell){if($cell->xpath('./*[local-name()="f"]'))throw new \DomainException('Remove formulas from the import workbook.');preg_match('/^([A-Z]+)[0-9]+$/',(string)$cell['r'],$m);if(!$m)throw new \DomainException('Invalid Excel cell address.');$col=0;foreach(str_split($m[1]) as $letter)$col=$col*26+ord($letter)-64;$raw=(string)($cell->xpath('./*[local-name()="v"]')[0]??'');$type=(string)$cell['t'];
                        $v=$type==='s'?($strings[(int)$raw]??''):($type==='inlineStr'?implode('',array_map('strval',$cell->xpath('.//*[local-name()="t"]'))):$raw);
                        if($col>14){if($v!=='')throw new \DomainException('Unexpected spreadsheet columns.');continue;}$values[$col-1]=$v;
                    }if(array_filter($values,fn($v)=>trim($v)!==''))$rows[]=$values;if(count($rows)>501)throw new \DomainException('Import up to 500 questions at a time.');}
            }finally{$zip->close();}
        }else{throw new \DomainException('Use XLSX or UTF-8 CSV; legacy XLS is not supported.');}
        if(count($rows)<2)throw new \DomainException('The spreadsheet contains no questions.');
        $headers=array_map(fn($v)=>strtolower(trim(ltrim((string)$v,"\xEF\xBB\xBF"))),array_shift($rows));if($headers!==self::HEADERS)throw new \DomainException('Use the sample column headers without renaming or reordering them.');
        return array_map(fn($r)=>array_combine(self::HEADERS,array_pad(array_map(fn($v)=>trim((string)$v),$r),14,'')),$rows);
    }
    public static function import(int $subject,?int $module,array $rows): array
    {
        ManagementService::checkSection('questions');if(!ManagementService::canWrite('questions',$subject)||!A::canAccessSubject($subject))throw new \DomainException('Access denied.');
        if(count($rows)>500||!$rows)throw new \DomainException('Import between 1 and 500 questions.');
        return DB::transaction(function()use($subject,$module,$rows){
            if(!ManagementService::find('subjects',$subject))throw new \DomainException('Subject not found.');
            if($module){$m=ManagementService::find('modules',$module);if(!$m||(int)$m['subject_id']!==$subject)throw new \DomainException('The module must belong to the selected subject.');}
            DB::one('SELECT id FROM subjects WHERE id=? FOR UPDATE',[$subject]);$created=0;$skipped=0;
            foreach($rows as $i=>$r){
                try{
                    $r['subject_id']=$subject;$r['module_id']=$module??'';$r['correct_option']=strtoupper($r['correct_option']??'');$r['difficulty']=($r['difficulty']??'')?:'medium';$r['default_marks']=($r['default_marks']??'')?:'1';$r['status']=($r['status']??'')?:'draft';
                    foreach(['question','option_a','option_b','option_c','option_d'] as $field){if(($r[$field.'_hi']??'')==='')$r[$field.'_hi']=$r[$field.'_en']??'';}
                    // Never overwrite existing questions/options used by historical attempts.
                    $text=V::str($r['question_en']??'','Question',1,5000);
                    if(DB::val("SELECT q.id FROM questions q JOIN question_translations t ON t.question_id=q.id JOIN languages l ON l.id=t.language_id AND l.code='en' WHERE q.subject_id=? AND q.module_id <=> ? AND t.question_text=? LIMIT 1",[$subject,$module,$text])){$skipped++;continue;}
                    ManagementService::save('questions',null,$r);$created++;
                }catch(\DomainException|\InvalidArgumentException $e){throw new \DomainException('Row '.($i+2).': '.$e->getMessage());}
            }return compact('created','skipped');
        });
    }
}
