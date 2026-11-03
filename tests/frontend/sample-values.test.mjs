import test from 'node:test';
import assert from 'node:assert/strict';
import {sampleValue,originalSampleValue} from '../../resources/js/lib/sample-values.ts';

test('decimal strings keep every significant digit without a floating point conversion',()=>{
    assert.equal(sampleValue('9007199254740993.123456789','Number'),'9.007.199.254.740.993,123456789');
    assert.equal(sampleValue('-1234.050000000','decimal(20, 9)'),'-1.234,05');
    assert.equal(sampleValue('500.000000000','Number'),'500');
    assert.equal(sampleValue('0.000000001','Number'),'0,000000001');
});

test('numeric values do not use the browser default rounding to three decimal places',()=>{
    assert.equal(sampleValue(1234.123456789),'1.234,123456789');
    assert.equal(sampleValue(1e21),'1.000.000.000.000.000.000.000');
    assert.equal(sampleValue(1e-24),'0,000000000000000000000001');
    assert.equal(sampleValue(0),'0');
});

test('numeric-looking identifiers and unknown column types remain unchanged',()=>{
    for(const type of [null,undefined,'String','Unknown',{}])assert.equal(sampleValue('1234567.000',type),'1234567.000');
    assert.equal(sampleValue('001234','Number'),'001234');
    assert.equal(sampleValue('1,234.56','Number'),'1,234.56');
    assert.equal(sampleValue('1e999','Number'),'1e999');
    assert.equal(sampleValue('<script>alert(1)</script>','Number'),'<script>alert(1)</script>');
});

test('date-only values and UTC midnight dates keep their calendar day in any browser timezone',()=>{
    assert.equal(sampleValue('2026-11-03','Date'),'03/11/2026');
    assert.equal(sampleValue('2026-11-03T00:00:00.000Z','Date'),'03/11/2026');
    assert.equal(sampleValue('2024-02-29','Date'),'29/02/2024');
    assert.equal(sampleValue('2026-11-03 00:00:00','Date'),'03/11/2026');
    assert.equal(sampleValue('2026-11-03T00:00:00','Date'),'03/11/2026');
});

test('timestamps, text columns and invalid calendar dates are not silently reduced to dates',()=>{
    for(const value of ['2026-11-03T12:30:00Z','2026-11-03T00:00:00-03:00','2026-02-29','2026-13-01','03/11/2026'])assert.equal(sampleValue(value,'Date'),value);
    assert.equal(sampleValue('2026-11-03','String'),'2026-11-03');
    assert.equal(sampleValue('2026-11-03T12:30:00Z','DateTime'),'2026-11-03T12:30:00Z');
});

test('original values remain available with their original decimal and date representation',()=>{
    assert.equal(sampleValue('500.000000000','Number',true),'500.000000000');
    assert.equal(sampleValue('2026-11-03T00:00:00.000Z','Date',true),'2026-11-03T00:00:00.000Z');
    assert.equal(originalSampleValue(false),'false');
    assert.equal(sampleValue(null,'Number'),'—');
    assert.equal(sampleValue(undefined),'—');
});
