import test from 'node:test';
import assert from 'node:assert/strict';
import {conversationApprovalTimeline,conversationReviewAction,conversationReviewState} from '../../resources/js/lib/conversation-approvals.ts';

const time=Date.parse('2026-10-30T13:00:00-03:00');
const review=(changes={})=>({id:'review-one',conversation_id:'conversation-one',project_id:'project-one',agent_run_id:'run-one',input_message_id:'input-one',status:'pending',fingerprint:'a'.repeat(64),created_at:'2026-10-30T12:59:00-03:00',expires_at:'2026-10-30T13:10:00-03:00',...changes});
const timeline=(messages,approvals)=>conversationApprovalTimeline(messages,approvals,'conversation-one','project-one');

test('only the loaded conversation and project can display their own agent reviews',()=>{
    const expected=review();
    const result=timeline([], [expected,review({id:'another-conversation',conversation_id:'conversation-two'}),review({id:'another-project',project_id:'project-two'}),review({id:'manual-operation',agent_run_id:null})]);
    assert.deepEqual(result.all,[expected]);
    assert.deepEqual(result.unanchored,[expected]);
    assert.deepEqual(conversationApprovalTimeline([], [expected],null,'project-one').all,[]);
});

test('a new request is placed after the response from its exact agent run',()=>{
    const messages=[{id:'input-one',role:'user'},{id:'assistant-one',role:'assistant',metadata:{run_id:'run-one'}},{id:'assistant-other',role:'assistant',metadata:{run_id:'run-two'}}];
    const result=timeline(messages,[review()]);
    assert.deepEqual(result.after['assistant-one'].map(item=>item.id),['review-one']);
    assert.equal(result.after['assistant-other'],undefined);
    assert.deepEqual(result.unanchored,[]);
});

test('legacy reviews use the input message and do not drift into another turn',()=>{
    const messages=[{id:'input-one',role:'user',created_at:'2026-10-30T12:58:00-03:00'},{id:'legacy-response',role:'assistant',created_at:'2026-10-30T12:58:30-03:00'},{id:'next-input',role:'user',created_at:'2026-10-30T12:59:30-03:00'},{id:'next-response',role:'assistant',created_at:'2026-10-30T13:00:00-03:00'}];
    assert.deepEqual(timeline(messages,[review()]).after['legacy-response'].map(item=>item.id),['review-one']);
    assert.equal(timeline(messages,[review()]).after['next-response'],undefined);
    assert.deepEqual(timeline([messages[0]],[review()]).after['input-one'].map(item=>item.id),['review-one']);
});

test('a review without a known anchor is retained in the current conversation',()=>{
    const item=review({input_message_id:null});
    assert.deepEqual(timeline([], [item]).unanchored,[item]);
});

test('a pending review cannot be decided after its deadline or with incomplete data',()=>{
    assert.equal(conversationReviewState(review(),time),'pending');
    assert.equal(conversationReviewState(review({expires_at:new Date(time).toISOString()}),time),'expired');
    for(const change of [{expires_at:null},{expires_at:'unknown'},{fingerprint:'invalid'},{id:null}])assert.equal(conversationReviewState(review(change),time),'unavailable');
});

test('authorization is distinct from execution and terminal states cannot request another decision',()=>{
    for(const status of ['approved','executing','completed','rejected','expired','cancelled','failed'])assert.equal(conversationReviewState(review({status}),time),status);
    assert.equal(conversationReviewState(review({status:'unknown'}),time),'unavailable');
    assert.deepEqual(conversationReviewAction(review({payload:{action:{action:'delete_draft'}}})),{label:'Autorizar exclusão',destructive:true});
    assert.deepEqual(conversationReviewAction(review({payload:{action:{action:'publish_app'}}})),{label:'Autorizar publicação',destructive:false});
});

test('history is ordered consistently and the same review is displayed only once',()=>{
    const later=review({id:'later',created_at:'2026-10-30T13:01:00-03:00'});
    const earlier=review();
    assert.deepEqual(timeline([], [later,earlier,later]).all.map(item=>item.id),['review-one','later']);
});
