import React, { useState, useRef, useEffect } from 'react';
import { MessageSquare, X, Send, Bot, User, Loader2, Calendar, Clock, MapPin } from 'lucide-react';
import { motion, AnimatePresence } from 'framer-motion';

const App = () => {
  const [isOpen, setIsOpen] = useState(false);
  const [messages, setMessages] = useState([
    { role: 'ai', text: 'Hello! I am your Credoq Assistant. I can help you find services, check availability, or manage your bookings. How can I help you today?' }
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
    fd.append('action', 'credoq_ai_chat_customer');
    fd.append('_ajax_nonce', window.CredoqAssistantCfg.nonce);
    fd.append('prompt', userPrompt);
    fd.append('history', JSON.stringify(history));

    try {
      const response = await fetch(window.CredoqAssistantCfg.ajaxUrl, {
        method: 'POST',
        body: fd
      });
      const data = await response.json();

      if (data.success) {
        const aiMsg = data.data.text;
        const newHistory = data.data.history;
        
        // Extract tool calls for rich rendering if any
        const lastEntry = newHistory[newHistory.length - 1];
        const toolResults = [];
        if (lastEntry && lastEntry.parts) {
          lastEntry.parts.forEach(p => {
             // We could render cards for services/slots here
          });
        }

        setMessages(prev => [...prev, { role: 'ai', text: aiMsg }]);
        setHistory(newHistory);
      } else {
        setMessages(prev => [...prev, { role: 'ai', text: 'Sorry, I encountered an error: ' + (data.data.message || 'Unknown error'), isError: true }]);
      }
    } catch (e) {
      setMessages(prev => [...prev, { role: 'ai', text: 'Connection lost. Please check your internet.', isError: true }]);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="cq-assistant-root">
      <AnimatePresence>
        {isOpen && (
          <motion.div 
            className="cq-assistant-window"
            initial={{ opacity: 0, y: 20, scale: 0.95 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: 20, scale: 0.95 }}
          >
            <div className="cq-assistant-header">
              <div className="cq-assistant-header-info">
                <Bot size={20} />
                <span>Credoq Assistant</span>
              </div>
              <button className="cq-close-btn" onClick={() => setIsOpen(false)}><X size={18} /></button>
            </div>

            <div className="cq-assistant-log" ref={logRef}>
              {messages.map((m, i) => (
                <div key={i} className={`cq-msg-row ${m.role}`}>
                  <div className={`cq-msg-bubble ${m.isError ? 'error' : ''}`}>
                    {m.text}
                  </div>
                </div>
              ))}
              {loading && (
                <div className="cq-msg-row ai">
                  <div className="cq-msg-bubble loading">
                    <Loader2 className="spin" size={16} />
                  </div>
                </div>
              )}
            </div>

            <div className="cq-assistant-input-area">
              <input 
                type="text" 
                placeholder="Ask me anything..." 
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={(e) => e.key === 'Enter' && handleSend()}
              />
              <button onClick={handleSend} disabled={loading || !input.trim()}>
                <Send size={18} />
              </button>
            </div>
          </motion.div>
        )}
      </AnimatePresence>

      <button className={`cq-assistant-toggle ${isOpen ? 'active' : ''}`} onClick={() => setIsOpen(!isOpen)}>
        {isOpen ? <X size={24} /> : <MessageSquare size={24} />}
      </button>
    </div>
  );
};

export default App;
