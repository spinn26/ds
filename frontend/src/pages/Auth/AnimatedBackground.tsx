import React from 'react';
import { Box } from '@mui/material';
import { motion } from 'framer-motion';

const circles = [
  { size: 300, x: '10%', y: '20%', color: 'rgba(76,175,80,0.15)', duration: 20 },
  { size: 200, x: '80%', y: '10%', color: 'rgba(255,152,0,0.12)', duration: 25 },
  { size: 400, x: '70%', y: '70%', color: 'rgba(76,175,80,0.1)', duration: 30 },
  { size: 150, x: '20%', y: '80%', color: 'rgba(33,150,243,0.1)', duration: 22 },
  { size: 250, x: '50%', y: '50%', color: 'rgba(255,152,0,0.08)', duration: 28 },
];

const AnimatedBackground: React.FC = () => {
  return (
    <Box
      sx={{
        position: 'fixed',
        inset: 0,
        background: 'linear-gradient(135deg, #f0f9f0 0%, #fafafa 40%, #fff8f0 100%)',
        overflow: 'hidden',
        zIndex: 0,
      }}
    >
      {circles.map((circle, i) => (
        <motion.div
          key={i}
          style={{
            position: 'absolute',
            left: circle.x,
            top: circle.y,
            width: circle.size,
            height: circle.size,
            borderRadius: '50%',
            background: circle.color,
            filter: 'blur(40px)',
          }}
          animate={{
            x: [0, 30, -20, 10, 0],
            y: [0, -20, 30, -10, 0],
            scale: [1, 1.1, 0.9, 1.05, 1],
          }}
          transition={{
            duration: circle.duration,
            repeat: Infinity,
            ease: 'easeInOut',
          }}
        />
      ))}

      {/* Floating particles */}
      {Array.from({ length: 20 }).map((_, i) => (
        <motion.div
          key={`p-${i}`}
          style={{
            position: 'absolute',
            left: `${Math.random() * 100}%`,
            top: `${Math.random() * 100}%`,
            width: 4 + Math.random() * 4,
            height: 4 + Math.random() * 4,
            borderRadius: '50%',
            background: i % 2 === 0 ? 'rgba(76,175,80,0.3)' : 'rgba(255,152,0,0.25)',
          }}
          animate={{
            y: [0, -100 - Math.random() * 200],
            opacity: [0, 1, 0],
          }}
          transition={{
            duration: 5 + Math.random() * 10,
            repeat: Infinity,
            delay: Math.random() * 5,
            ease: 'easeOut',
          }}
        />
      ))}
    </Box>
  );
};

export default AnimatedBackground;
