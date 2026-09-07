import React, { useState, useRef, useEffect } from 'react';
import { Send, Bot, User, Settings, CheckCircle2, AlertCircle, Loader2, PlusCircle, Calendar, Users, Ticket, FileText } from 'lucide-react';

const App = () => {
  const [messages, setMessages] = useState([
    { role: 'ai', text: 'Neural Link Established. I am your Credoq AI Assistant. How can I help you manage your business today?' }
  ]);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [history, setHistory] = useState([]);
  const logRef = useRef(null);

  useEffect(() => {
    if (logRef.current) {
      logRef.scrollTop = logRef.current.scrollHeight;
    }
  }, [messages]);

  const handleSend = async () => {
    if (!input.trim() || loading) return;

    const userPrompt = input.trim();
    setInput('');
    setMessages(prev => [...prev, { role: 'user', text: userPrompt }]);
    setLoading(true);

    const fd = new FormData();
    fd.append('action', 'credoq_ai_chat');
    fd.append('_ajax_nonce', window.CredoqEngineAdmin.aiNonce);
    fd.append('prompt', userPrompt);
    fd.append('history', JSON.stringify(history));

    try {
      const response = await fetch(window.CredoqEngineAdmin.ajaxUrl, {
        method: 'POST',
        body: fd
      });
      const data = await response.json();

      if (data.success) {
        const aiMsg = data.data.text;
        const newHistory = data.data.history;
        
        // Extract tool calls from history for visualization
        const lastEntry = newHistory[newHistory.length - 1];
        const toolCalls = [];
        if (lastEntry && lastEntry.parts) {
          lastEntry.parts.forEach(p => {
            if (p.functionCall) toolCalls.push(p.functionCall);
          });
        }

        setMessages(prev => [...prev, { role: 'ai', text: aiMsg, toolCalls }]);
        setHistory(newHistory);
      } else {
        setMessages(prev => [...prev, { role: 'ai', text: 'Error: ' + (data.data.message || 'Unknown error'), isError: true }]);
      }
    } catch (e) {
      setMessages(prev => [...prev, { role: 'ai', text: 'Network Error: ' + e.message, isError: true }]);
    } finally {
      setLoading(false);
    }
  };

  const handleApplyProposal = async (proposalId, token) => {
    setLoading(true);
    const fd = new FormData();
    fd.append('action', 'credoq_ai_chat'); // Use same chat but specific confirmation prompt
    fd.append('_ajax_nonce', window.CredoqEngineAdmin.aiNonce);
    // We send a hidden confirmation prompt that the AI Bridge will route
    fd.append('prompt', `confirm_proposal:${proposalId}:${token}`);
    fd.append('history', JSON.stringify(history));

    try {
      const response = await fetch(window.CredoqEngineAdmin.ajaxUrl, { method: 'POST', body: fd });
      const data = await response.json();
      if (data.success) {
        setMessages(prev => [...prev, { role: 'ai', text: data.data.text }]);
        setHistory(data.data.history);
      }
    } catch (e) {
      alert('Error applying: ' + e.message);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="cq-command-container">
      <div className="cq-command-header">
        <div className="cq-ai-status">
          <div className={`status-dot ${loading ? 'pulse' : 'online'}`}></div>
          <span>{loading ? 'AI Thinking...' : 'AI Online'}</span>
        </div>
      </div>

      <div className="cq-chat-log" ref={logRef}>
        {messages.map((m, i) => (
          <div key={i} className={`cq-msg-row ${m.role}`}>
            <div className="cq-msg-icon">
              {m.role === 'ai' ? <Bot size={18} /> : <User size={18} />}
            </div>
            <div className={`cq-msg-bubble ${m.isError ? 'error' : ''}`}>
              <div className="cq-msg-text">{m.text}</div>
              
              {m.toolCalls && m.toolCalls.map((call, ti) => (
                <ToolCallCard key={ti} call={call} onConfirm={handleApplyProposal} />
              ))}
            </div>
          </div>
        ))}
        {loading && (
          <div className="cq-msg-row ai">
            <div className="cq-msg-icon"><Bot size={18} /></div>
            <div className="cq-msg-bubble loading">
              <Loader2 className="spin" size={18} />
            </div>
          </div>
        )}
      </div>

      <div className="cq-command-input-area">
        <input
          type="text"
          className="cq-command-input"
          placeholder="Command your business... (e.g. 'Create a $50 monthly plan')"
          value={input}
          onChange={(e) => setInput(e.target.value)}
          onKeyDown={(e) => e.key === 'Enter' && handleSend()}
          disabled={loading}
        />
        <button 
          className="cq-command-send-btn" 
          onClick={handleSend}
          disabled={loading || !input.trim()}
        >
          <Send size={20} />
        </button>
      </div>
    </div>
  );
};

const ToolCallCard = ({ call, onConfirm }) => {
  const isCreation = call.name === 'credoq_propose_creation';
  
  if (!isCreation) {
    return (
      <div className="cq-tool-mini">
        <Settings size={12} />
        <span>Executing: {call.name}</span>
      </div>
    );
  }

  const { resource, data, proposal_id, confirm_token } = call.args;
  
  // This is a draft proposal
  return (
    <div className="cq-draft-card">
      <div className="cq-draft-header">
        <PlusCircle size={16} />
        <span>Draft Proposal: {resource.replace('_', ' ')}</span>
      </div>
      <div className="cq-draft-body">
        {resource === 'membership_plan' && <PlanDraft data={data} />}
        {resource === 'service' && <ServiceDraft data={data} />}
        {resource === 'event' && <EventDraft data={data} />}
        {resource === 'staff' && <StaffDraft data={data} />}
      </div>
      <div className="cq-draft-footer">
        <button 
          className="cq-draft-approve" 
          onClick={() => onConfirm(proposal_id, confirm_token)}
          disabled={!proposal_id}
        >
          <CheckCircle2 size={14} />
          Review & Publish
        </button>
      </div>
    </div>
  );
};

const PlanDraft = ({ data }) => (
  <>
    <div className="draft-field"><strong>Name:</strong> {data.name}</div>
    <div className="draft-field"><strong>Duration:</strong> {data.duration_days} days</div>
    <div className="draft-field"><strong>Credits:</strong> {data.slot_credit}</div>
  </>
);

const ServiceDraft = ({ data }) => (
  <>
    <div className="draft-field"><strong>Title:</strong> {data.title}</div>
    <div className="draft-field"><strong>Price:</strong> ${data.price}</div>
    <div className="draft-field"><strong>Duration:</strong> {data.duration} min</div>
  </>
);

const EventDraft = ({ data }) => (
  <>
    <div className="draft-field"><strong>Event:</strong> {data.title}</div>
    <div className="draft-field"><strong>Starts:</strong> {data.start_datetime}</div>
    <div className="draft-field"><strong>Capacity:</strong> {data.capacity}</div>
  </>
);

const StaffDraft = ({ data }) => (
  <>
    <div className="draft-field"><strong>Name:</strong> {data.display_name}</div>
    <div className="draft-field"><strong>Email:</strong> {data.email}</div>
  </>
);

export default App;
