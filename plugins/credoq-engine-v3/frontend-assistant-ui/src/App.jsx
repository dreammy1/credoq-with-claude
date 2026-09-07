import React, { useState, useRef, useEffect } from 'react';
import { MessageSquare, X, Send, Bot, User, Loader2, Calendar, Users, Info } from 'lucide-react';

const App = () => {
  const [isOpen, setIsOpen] = useState(false);
  const [messages, setMessages] = useState([
    { role: 'ai', text: 'Hi! I am your Credoq Assistant. I can help you find services, check availability, or answer questions about our events. How can I help?' }
  ]);
  const [input, setInput] = useState('');
  const [loading, setLoading] = useState(false);
  const [history, setHistory] = useState([]);
  const logRef = useRef(null);

  useEffect(() => {
    if (logRef.current) {
      logRef.scrollTop = logRef.current.scrollHeight;
    }
  }, [messages, isOpen]);

  const handleSend = async () => {
    if (!input.trim() || loading) return;

    const userPrompt = input.trim();
    setInput('');
    setMessages(prev => [...prev, { role: 'user', text: userPrompt }]);
    setLoading(true);

    const fd = new FormData();
    fd.append('action', 'credoq_ai_chat_customer'); // New action for customer context
    fd.append('_ajax_nonce', window.CredoqFrontend.aiNonce);
    fd.append('prompt', userPrompt);
    fd.append('history', JSON.stringify(history));

    try {
      const response = await fetch(window.CredoqFrontend.ajaxUrl, {
        method: 'POST',
        body: fd
      });
      const data = await response.json();

      if (data.success) {
        setMessages(prev => [...prev, { role: 'ai', text: data.data.text }]);
        setHistory(data.data.history);
      } else {
        setMessages(prev => [...prev, { role: 'ai', text: 'I am having a little trouble connecting right now. Please try again in a moment.', isError: true }]);
      }
    } catch (e) {
      setMessages(prev => [...prev, { role: 'ai', text: 'Connection lost. Please check your internet.', isError: true }]);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className={`cq-ai-assistant-root ${isOpen ? 'open' : ''}`}>
      {!isOpen && (
        <button className="cq-ai-toggle" onClick={() => setIsOpen(true)}>
          <MessageSquare size={24} />
          <span className="cq-ai-badge">AI</span>
        </button>
      )}

      {isOpen && (
        <div className="cq-ai-window">
          <div className="cq-ai-header">
            <div className="cq-ai-header-left">
              <div className="cq-ai-avatar">
                <Bot size={20} />
              </div>
              <div className="cq-ai-title">
                <strong>Credoq Assistant</strong>
                <span className="status">Online</span>
              </div>
            </div>
            <button className="cq-ai-close" onClick={() => setIsOpen(false)}>
              <X size={20} />
            </button>
          </div>

          <div className="cq-ai-chat-log" ref={logRef}>
            {messages.map((m, i) => (
              <div key={i} className={`cq-ai-msg ${m.role}`}>
                <div className="cq-ai-bubble">
                  {m.text}
                </div>
              </div>
            ))}
            {loading && (
              <div className="cq-ai-msg ai">
                <div className="cq-ai-bubble loading">
                  <Loader2 className="spin" size={16} />
                </div>
              </div>
            )}
          </div>

          <div className="cq-ai-input-wrap">
            <input
              type="text"
              className="cq-ai-input"
              placeholder="Ask anything..."
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && handleSend()}
              disabled={loading}
            />
            <button 
              className="cq-ai-send" 
              onClick={handleSend}
              disabled={loading || !input.trim()}
            >
              <Send size={18} />
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

export default App;
