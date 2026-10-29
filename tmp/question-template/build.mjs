import fs from 'node:fs/promises';
import { Workbook, SpreadsheetFile } from '@oai/artifact-tool';
const workbook=Workbook.create();const sheet=workbook.worksheets.add('Questions');
const headers=['question_en','question_hi','option_a_en','option_a_hi','option_b_en','option_b_hi','option_c_en','option_c_hi','option_d_en','option_d_hi','correct_option','default_marks','difficulty','status'];
sheet.getRange('A1:N3').values=[headers,
 ['Which HTML tag creates a hyperlink?','हाइपरलिंक बनाने के लिए कौन सा HTML टैग है?','<a>','<a>','<p>','<p>','<div>','<div>','<img>','<img>','A',1,'easy','draft'],
 ['Which keyword declares a JavaScript constant?','JavaScript में कॉन्स्टेंट घोषित करने वाला कीवर्ड कौन सा है?','var','var','let','let','const','const','static','static','C',1,'easy','draft']];
sheet.getRange('A1:N3').format.font={name:'Arial',size:10};sheet.getRange('A1:N3').format.columnWidth=19;sheet.getRange('A1:B3').format.columnWidth=42;sheet.getRange('A1:N3').format.wrapText=true;sheet.getRange('A1:N3').format.verticalAlignment='center';
sheet.getRange('A1:N1').format.fill='#17657A';sheet.getRange('A1:N1').format.font={name:'Arial',size:10,bold:true,color:'#FFFFFF'};sheet.getRange('A1:N1').format.horizontalAlignment='center';sheet.getRange('A1:N1').format.rowHeight=32;sheet.getRange('A2:N3').format.rowHeight=58;sheet.getRange('L2:L3').setNumberFormat('0');sheet.freezePanes.freezeRows(1);sheet.freezePanes.freezeColumns(2);workbook.recalculate();
const dir='C:/xampp/htdocs/lms/storage/templates';await fs.mkdir(dir,{recursive:true});await (await SpreadsheetFile.exportXlsx(workbook)).save(dir+'/question-bank-sample.xlsx');
const preview=await workbook.render({sheetName:'Questions',autoCrop:'all',scale:1,format:'png'});await fs.writeFile('C:/xampp/htdocs/lms/tmp/question-template/preview.png',new Uint8Array(await preview.arrayBuffer()));
console.log((await workbook.inspect({kind:'region',sheetId:'Questions',range:'A1:N3',maxChars:1500,tableMaxRows:3,tableMaxCols:4})).ndjson);
