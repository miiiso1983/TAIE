import React, { useEffect, useRef, useState } from 'react';
import { Button, Field, Spinner, Textarea } from '@fluentui/react-components';
import { api, requestKey } from './api';
import { Notice, PageHeading } from './components';

export function Chat({ navigate }) {
    const [conversations, setConversations] = useState([]), [selected, setSelected] = useState(null), [messages, setMessages] = useState([]);
    const [question, setQuestion] = useState(''), [busy, setBusy] = useState(false), [loading, setLoading] = useState(false), [error, setError] = useState('');
    const [listPage, setListPage] = useState(1), [lastPage, setLastPage] = useState(1);
    const pending = useRef(null), end = useRef(null);
    const refresh = async (page = 1) => { const response = await api(`/ai/conversations?page=${page}`); setConversations(old => page === 1 ? response.data : [...old, ...response.data]); setListPage(page); setLastPage(response.meta.last_page); };
    useEffect(() => { refresh().catch(e => setError(e.message)); }, []);
    useEffect(() => { end.current?.scrollIntoView({ block: 'nearest' }); }, [messages, busy]);
    const open = async uuid => {
        if (busy || loading) return;
        setLoading(true); setError(''); pending.current = null;
        try {
            const rows = []; let page = 1, last = 1;
            do { const response = await api(`/ai/conversations/${uuid}/messages?page=${page}&per_page=100`); rows.push(...response.data); last = response.meta.last_page; page++; } while (page <= last);
            setSelected(uuid); setMessages(rows); setQuestion('');
        } catch (e) { setError(e.message); } finally { setLoading(false); }
    };
    const send = async event => {
        event.preventDefault(); if (busy || loading || !question.trim()) return;
        const text = question.trim();
        if (!pending.current || pending.current.question !== text || pending.current.conversation_uuid !== selected) pending.current = { key: requestKey(), question: text, conversation_uuid: selected };
        setBusy(true); setError('');
        try {
            const response = await api('/ai/ask', { method: 'POST', key: pending.current.key, data: { question: text, conversation_uuid: selected } });
            setSelected(response.data.conversation_uuid);
            setMessages(old => [...old, { role: 'user', content: text }, { role: 'assistant', content: response.data.answer, citations: response.data.citations }]);
            setQuestion(''); pending.current = null;
            refresh().catch(() => {});
        } catch (e) { setError(e.message); } finally { setBusy(false); }
    };
    return <div className="stack"><PageHeading eyebrow="وكيل شركتك" title="تحدث مع TAIE" description="وكيل AI للمبيعات والموارد البشرية. المحادثة تعتمد على مصادر الشركة المتاحة لصلاحياتك؛ لا ينفذ إجراءات أو قرارات بالنيابة عنك." />
        <div className="chat-layout"><aside className="chat-history" aria-label="سجل المحادثات"><Button appearance="primary" disabled={busy || loading} onClick={() => { setSelected(null); setMessages([]); setQuestion(''); setError(''); pending.current = null; }}>محادثة جديدة</Button><h2>محادثاتي</h2>{conversations.map(item => <Button key={item.uuid} appearance={selected === item.uuid ? 'secondary' : 'subtle'} disabled={busy || loading} aria-pressed={selected === item.uuid} onClick={() => open(item.uuid)}>{item.last_message?.preview || 'محادثة مع TAIE'}</Button>)}{listPage < lastPage && <Button disabled={busy || loading} onClick={() => refresh(listPage + 1).catch(e => setError(e.message))}>محادثات أقدم</Button>}</aside>
        <section className="chat-panel" aria-label="المحادثة مع TAIE"><div className="chat-messages" role="log" aria-live="polite">{!messages.length && <div className="chat-welcome"><h2>كيف أساعدك اليوم؟</h2><p>أضف مستندات شركتك وفهرسها، ثم اسأل عن المبيعات أو الموارد البشرية. الأسئلة المتتابعة تبقى في المحادثة نفسها.</p>{['ما سياسة متابعة العملاء في شركتنا؟', 'لخّص سياسة الإجازات المعتمدة', 'ما خطوات الموافقة على الخصومات؟'].map(text => <Button key={text} disabled={busy || loading} onClick={() => setQuestion(text)}>{text}</Button>)}<Button onClick={() => navigate('knowledge')}>تجهيز معرفة الشركة</Button></div>}{messages.map((message, i) => <article key={i} className={`chat-message chat-${message.role}`}><strong>{message.role === 'user' ? 'أنت' : 'TAIE'}</strong><p>{message.content}</p>{message.citations?.length > 0 && <details><summary>مصادر الإجابة ({message.citations.length})</summary><ol>{message.citations.map((source, j) => <li key={j}>{source.reference ? `[${source.reference}] ` : ''}{source.title || source.document_title}{source.origin === 'external_reference' && ' — مرجع خارجي إرشادي'}{source.url && /^https:\/\//.test(source.url) && <a href={source.url} target="_blank" rel="noopener noreferrer"> فتح المرجع</a>}</li>)}</ol></details>}</article>)}{(busy || loading) && <Spinner label={busy ? 'TAIE يجهّز الإجابة…' : 'جارٍ تحميل المحادثة…'} />}<div ref={end}/></div>
        {error && <Notice intent="error">{error} يمكنك إعادة الإرسال؛ يُعاد استخدام معرّف الطلب لتجنب احتسابه مرتين.</Notice>}<form className="chat-composer" onSubmit={send}><Field label="رسالتك إلى TAIE"><Textarea value={question} onChange={(_, data) => setQuestion(data.value)} maxLength={8000} disabled={busy || loading} resize="vertical" placeholder="اكتب سؤالك، أو تابع السؤال السابق…" /></Field><Button appearance="primary" type="submit" disabled={busy || loading || !question.trim()}>إرسال</Button><p className="caption">الطلبات الناجحة تُحتسب من رصيد الشركة. راجع المصادر قبل اعتماد الإجابة.</p></form></section></div></div>;
}
