import test from 'node:test';
import assert from 'node:assert/strict';
import {catalogAppCounts} from '../../resources/js/lib/catalog-counts.ts';

const unknown = {all:null,mine:null,published:null};

test('a temporarily unavailable or denied catalog is not presented as zero applications', () => {
    assert.deepEqual(catalogAppCounts({status:'unavailable',count:null},[]),unknown);
    assert.deepEqual(catalogAppCounts({status:'access_denied',count:null},[]),unknown);
    assert.deepEqual(catalogAppCounts(undefined,[]),unknown);
});

test('a confirmed empty list is distinct from an unavailable query', () => {
    assert.deepEqual(catalogAppCounts({status:'available',count:0},[]),{all:0,mine:0,published:0});
    assert.deepEqual(catalogAppCounts({status:'available',count:2},[{state:'draft'},{state:'published'}]),{all:2,mine:1,published:1});
});

test('an incomplete or malformed response cannot claim confirmed totals', () => {
    assert.deepEqual(catalogAppCounts({status:'available',count:72},[{state:'draft'}]),unknown);
    assert.deepEqual(catalogAppCounts({status:'available',count:1},[null]),unknown);
});

test('unknown publication state keeps the verified total without inventing desk subtotals', () => {
    assert.deepEqual(catalogAppCounts({status:'available',count:3},[{state:'draft'},{state:'published'},{state:'unknown'}]),{all:3,mine:null,published:null});
});
